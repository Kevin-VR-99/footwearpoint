<?php

namespace App\Services\Reporte;

use App\Models\Campana;
use App\Models\DistribuidoraLinea;
use App\Models\Marca;
use App\Models\PedidoDetalle;
use Illuminate\Database\Eloquent\Builder;

/**
 * TG-253 (R11, E14-04) — Productos más solicitados y marcas con más demanda,
 * por temporada (campaña en el código), para orientar las compras a fábrica.
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

    public function ejecutar(
        ?int $campanaId = null,
        ?int $marcaId = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?int $cicloId = null,
    ): array {
        $base = fn () => $this->base($campanaId, $marcaId, $desde, $hasta, $cicloId);

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
            ->selectRaw('producto_campana.id as producto_campana_id, productos.nombre as producto, productos.modelo as modelo, marcas.nombre as marca, campanas.nombre as temporada, SUM(pedido_detalle.cantidad) as piezas, SUM(pedido_detalle.subtotal) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->groupBy('producto_campana.id', 'productos.nombre', 'productos.modelo', 'marcas.nombre', 'campanas.nombre')
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
            ->selectRaw('campanas.id as id, campanas.nombre as nombre, lineas.nombre as linea, SUM(pedido_detalle.cantidad) as piezas, SUM(pedido_detalle.subtotal) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->groupBy('campanas.id', 'campanas.nombre', 'lineas.nombre')
            ->orderByDesc('piezas')->orderBy('campanas.nombre')
            ->toBase()->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'nombre' => (string) $r->nombre,
                'linea' => (string) $r->linea,
                'piezas' => (int) $r->piezas,
                'pedidos' => (int) $r->pedidos,
                'monto' => round((float) $r->monto, 2),
            ])->all();

        $totales = $base()
            ->selectRaw('COALESCE(SUM(pedido_detalle.cantidad), 0) as piezas, COALESCE(SUM(pedido_detalle.subtotal), 0) as monto, COUNT(DISTINCT pedido_detalle.pedido_id) as pedidos')
            ->toBase()->first();

        return [
            'filtros' => ['campana_id' => $campanaId, 'marca_id' => $marcaId, 'desde' => $desde, 'hasta' => $hasta, 'ciclo_id' => $cicloId],
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

    /** Temporadas para el filtro: las de líneas que la distribuidora tiene o tuvo. */
    public function temporadasParaFiltro()
    {
        return Campana::query()
            ->with('linea:id,nombre')
            ->whereIn('linea_id', DistribuidoraLinea::query()->select('linea_id'))
            ->orderByDesc('fecha_inicio')->orderBy('nombre')
            ->get(['id', 'nombre', 'linea_id']);
    }

    public function marcasParaFiltro()
    {
        return Marca::query()->orderBy('nombre')->get(['id', 'nombre']);
    }

    private function base(?int $campanaId, ?int $marcaId, ?string $desde, ?string $hasta, ?int $cicloId): Builder
    {
        return PedidoDetalle::query()
            ->join('pedidos', function ($j) {
                $j->on('pedidos.id', '=', 'pedido_detalle.pedido_id')
                    ->on('pedidos.distribuidora_id', '=', 'pedido_detalle.distribuidora_id');
            })
            ->join('producto_campana', 'producto_campana.id', '=', 'pedido_detalle.producto_campana_id')
            ->join('campanas', 'campanas.id', '=', 'producto_campana.campana_id')
            ->join('lineas', 'lineas.id', '=', 'campanas.linea_id')
            ->join('productos', 'productos.id', '=', 'producto_campana.producto_id')
            ->join('marcas', 'marcas.id', '=', 'productos.marca_id')
            ->whereNotIn('pedidos.estado', self::ESTADOS_EXCLUIDOS)
            ->whereNotNull('pedidos.fecha_colocacion')
            ->when($desde, fn ($q) => $q->whereDate('pedidos.fecha_colocacion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('pedidos.fecha_colocacion', '<=', $hasta))
            ->when($cicloId, fn ($q) => $q->where('pedidos.ciclo_compra_id', $cicloId))
            ->when($campanaId, fn ($q) => $q->where('campanas.id', $campanaId))
            ->when($marcaId, fn ($q) => $q->where('marcas.id', $marcaId));
    }
}
