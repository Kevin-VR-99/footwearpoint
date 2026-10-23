<?php

namespace App\Services\Reporte;

use App\Models\PedidoDetalle;
use Illuminate\Database\Eloquent\Builder;

/**
 * TG-253 (R11, E14-04) — Productos más solicitados y marcas con más demanda,
 * por temporada global (Primavera-Verano / Otoño-Invierno, iguales para todas
 * las líneas), para orientar las compras a fábrica.
 *
 * Reglas (decididas por el PO):
 *   - Solo pedidos (no ventas de mostrador): la demanda es lo que se pidió.
 *   - Cuentan desde que se colocan, filtrando por fecha de colocación. Sin
 *     borradores, rechazados, descartados ni vencidos en recolección; los
 *     "no surtido" SÍ cuentan (fue demanda real que no se cubrió), igual que
 *     las líneas no surtidas.
 *   - Se ordena por piezas y se muestra también el monto (subtotal pedido).
 *
 * El catálogo es compartido; el detalle del pedido es de la distribuidora
 * (BelongsToTenant en PedidoDetalle).
 */
class DemandaPorTemporadaMarcaAction
{
    public const ESTADOS_EXCLUIDOS = ['borrador', 'rechazado', 'descartado', 'vencido_recoleccion'];

    public const TOP_PRODUCTOS = 20;

    /**
     * @param  ?int  $temporadaId  temporada global (temporadas.id)
     * @param  ?int  $campanaId    solo por compatibilidad con la API anterior
     *                             (campana_id): filtra una línea en una temporada
     */
    public function ejecutar(
        ?int $temporadaId = null,
        ?int $marcaId = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?int $cicloId = null,
        ?int $campanaId = null,
    ): array {
        $base = fn () => $this->base($temporadaId, $marcaId, $desde, $hasta, $cicloId)
            ->when($campanaId, fn ($q) => $q->where('campanas.id', $campanaId));

        $porMarca = $base()
            ->selectRaw('marcas.id as id, marcas.nombre as nombre, SUM(pedido_detalle.cantidad) as piezas, SUM(pedido_detalle.subtotal) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos, COUNT(DISTINCT productos.id) as modelos')
            ->groupBy('marcas.id', 'marcas.nombre')
            ->orderByDesc('piezas')->orderBy('marcas.nombre')
            ->toBase()->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'nombre' => (string) $r->nombre,
                'piezas' => (int) $r->piezas,
                'pedidos' => (int) $r->pedidos,
                'modelos' => (int) $r->modelos,
                'monto' => round((float) $r->monto, 2),
            ])->all();

        $productos = $base()
            ->selectRaw('producto_campana.id as producto_campana_id, productos.nombre as producto, productos.modelo as modelo, marcas.nombre as marca, temporadas.nombre as temporada, SUM(pedido_detalle.cantidad) as piezas, SUM(pedido_detalle.subtotal) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->groupBy('producto_campana.id', 'productos.nombre', 'productos.modelo', 'marcas.nombre', 'temporadas.nombre')
            ->orderByDesc('piezas')->orderBy('productos.nombre')
            ->limit(self::TOP_PRODUCTOS)
            ->toBase()->get()
            ->map(fn ($r) => [
                'producto_campana_id' => (int) $r->producto_campana_id,
                'producto' => (string) $r->producto,
                'modelo' => (string) $r->modelo,
                'marca' => (string) $r->marca,
                'temporada' => (string) $r->temporada,
                'piezas' => (int) $r->piezas,
                'pedidos' => (int) $r->pedidos,
                'monto' => round((float) $r->monto, 2),
            ])->all();

        $porTemporada = $base()
            ->selectRaw('temporadas.id as id, temporadas.nombre as nombre, SUM(pedido_detalle.cantidad) as piezas, SUM(pedido_detalle.subtotal) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->groupBy('temporadas.id', 'temporadas.nombre')
            ->orderByDesc('piezas')->orderBy('temporadas.nombre')
            ->toBase()->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'nombre' => (string) $r->nombre,
                'piezas' => (int) $r->piezas,
                'pedidos' => (int) $r->pedidos,
                'monto' => round((float) $r->monto, 2),
            ])->all();

        $totales = $base()
            ->selectRaw('COALESCE(SUM(pedido_detalle.cantidad), 0) as piezas, COALESCE(SUM(pedido_detalle.subtotal), 0) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->toBase()->first();

        return [
            'filtros' => ['temporada_id' => $temporadaId, 'campana_id' => $campanaId, 'marca_id' => $marcaId, 'desde' => $desde, 'hasta' => $hasta, 'ciclo_id' => $cicloId],
            'por_marca' => $porMarca,
            'productos' => $productos,
            'por_temporada' => $porTemporada,
            'totales' => [
                'piezas' => (int) ($totales->piezas ?? 0),
                'pedidos' => (int) ($totales->pedidos ?? 0),
                'monto' => round((float) ($totales->monto ?? 0), 2),
            ],
        ];
    }

    /** Temporadas globales para el filtro: las que tienen productos de líneas que la distribuidora tiene o tuvo. */
    public function temporadasParaFiltro()
    {
        // Caché por distribuidora (se invalida al cambiar temporadas o líneas).
        return app(\App\Support\Cache\CatalogoCache::class)->temporadasParaFiltro();
    }

    public function marcasParaFiltro()
    {
        return app(\App\Support\Cache\CatalogoCache::class)->marcas();
    }

    private function base(?int $temporadaId, ?int $marcaId, ?string $desde, ?string $hasta, ?int $cicloId): Builder
    {
        return PedidoDetalle::query()
            ->join('pedidos', function ($j) {
                $j->on('pedidos.id', '=', 'pedido_detalle.pedido_id')
                    ->on('pedidos.distribuidora_id', '=', 'pedido_detalle.distribuidora_id');
            })
            ->join('producto_campana', 'producto_campana.id', '=', 'pedido_detalle.producto_campana_id')
            ->join('campanas', 'campanas.id', '=', 'producto_campana.campana_id')
            ->join('temporadas', 'temporadas.id', '=', 'campanas.temporada_id')
            ->join('productos', 'productos.id', '=', 'producto_campana.producto_id')
            ->join('marcas', 'marcas.id', '=', 'productos.marca_id')
            ->whereNotIn('pedidos.estado', self::ESTADOS_EXCLUIDOS)
            ->whereNotNull('pedidos.fecha_colocacion')
            ->when($desde, fn ($q) => $q->whereDate('pedidos.fecha_colocacion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('pedidos.fecha_colocacion', '<=', $hasta))
            ->when($cicloId, fn ($q) => $q->where('pedidos.ciclo_compra_id', $cicloId))
            ->when($temporadaId, fn ($q) => $q->where('campanas.temporada_id', $temporadaId))
            ->when($marcaId, fn ($q) => $q->where('marcas.id', $marcaId));
    }
}
