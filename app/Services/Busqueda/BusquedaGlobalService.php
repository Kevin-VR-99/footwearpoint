<?php

namespace App\Services\Busqueda;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\ImagenDeProducto;
use App\Support\Tenant;

/**
 * Búsqueda global del panel de la distribuidora (Ctrl+K).
 *
 * Todo se consulta con los scopes de tenant, y cada grupo solo aparece si la
 * persona puede abrir la pantalla a la que lleva el enlace:
 *   - pedidos y productos/variantes: todo el personal activo;
 *   - clientes directos y mayoristas: solo el administrador (sus listados
 *     son de 'role:admin_distribuidora').
 */
class BusquedaGlobalService
{
    public const MINIMO = 2;

    public const POR_GRUPO = 5;

    /** Staff activo de la distribuidora actual, o null si no es personal de ella. */
    public function staff(?Usuario $usuario): ?DistribuidoraStaff
    {
        if ($usuario === null || Tenant::id() === null) {
            return null;
        }

        return DistribuidoraStaff::query()
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->first();
    }

    /**
     * @return array<string, array{titulo: string, items: array<int, array{titulo: string, detalle: string, url: string}>}>
     */
    public function buscar(?Usuario $usuario, string $termino): array
    {
        $termino = trim($termino);
        $staff = $this->staff($usuario);
        if ($staff === null || mb_strlen($termino) < self::MINIMO) {
            return [];
        }

        $like = '%'.addcslashes($termino, '%_\\').'%';
        $esAdmin = $staff->tipo === 'administrador';

        $grupos = [
            'pedidos' => ['titulo' => 'Pedidos', 'items' => $this->pedidos($like)],
        ];
        if ($esAdmin) {
            $grupos['clientes'] = ['titulo' => 'Clientes directos', 'items' => $this->clientes($like)];
            $grupos['mayoristas'] = ['titulo' => 'Clientes mayoristas', 'items' => $this->mayoristas($like)];
        }
        $grupos['productos'] = ['titulo' => 'Productos', 'items' => $this->productos($like)];
        $grupos['variantes'] = ['titulo' => 'Variantes (SKU)', 'items' => $this->variantes($like)];

        return array_filter($grupos, fn ($g) => $g['items'] !== []);
    }

    private function pedidos(string $like): array
    {
        return Pedido::query()
            ->with(['clienteDirecto:id,nombre', 'revendedorAfiliacion.revendedor:id,nombre'])
            ->where('folio', 'like', $like)
            ->orderByDesc('id')->limit(self::POR_GRUPO)->get()
            ->map(fn (Pedido $p) => [
                'titulo' => $p->folio,
                'detalle' => trim(($p->clienteDirecto?->nombre ?? $p->revendedorAfiliacion?->revendedor?->nombre ?? 'Sin cliente').' · '.str_replace('_', ' ', (string) $p->estado).' · $'.number_format((float) $p->total, 2)),
                'url' => route('pedidos.show', $p->id),
            ])->all();
    }

    private function clientes(string $like): array
    {
        return ClienteDirecto::query()
            ->where(fn ($q) => $q->where('nombre', 'like', $like)->orWhere('telefono', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('nombre')->limit(self::POR_GRUPO)->get()
            ->map(fn (ClienteDirecto $c) => [
                'titulo' => $c->nombre,
                'detalle' => implode(' · ', array_filter([$c->telefono, $c->email])) ?: 'Sin contacto',
                'url' => route('distribuidora.clientes-directos', ['buscar' => $c->nombre]),
            ])->all();
    }

    private function mayoristas(string $like): array
    {
        return RevendedorDistribuidora::query()
            ->with('revendedor:id,nombre,telefono,email')
            ->where(fn ($q) => $q->where('codigo_interno', 'like', $like)
                ->orWhereHas('revendedor', fn ($r) => $r->where('nombre', 'like', $like)->orWhere('telefono', 'like', $like)->orWhere('email', 'like', $like)))
            ->limit(self::POR_GRUPO)->get()
            ->map(fn (RevendedorDistribuidora $a) => [
                'titulo' => (string) $a->revendedor?->nombre,
                'detalle' => implode(' · ', array_filter([$a->codigo_interno, $a->revendedor?->telefono, $a->revendedor?->email])) ?: 'Sin contacto',
                'url' => route('distribuidora.mayoristas', ['buscar' => (string) $a->revendedor?->nombre]),
            ])->all();
    }

    /** Solo productos del catálogo que esta distribuidora maneja (sus líneas y temporadas activas). */
    private function productos(string $like): array
    {
        $publicaciones = app(CatalogoVisible::class)->consultaIncluyendoOcultos()
            ->with('producto.marca')
            ->whereHas('producto', fn ($p) => $p->where('modelo', 'like', $like)->orWhere('nombre', 'like', $like)
                ->orWhereHas('variantes', fn ($v) => $v->where('sku', 'like', $like)))
            ->get()
            ->unique('producto_id')->take(self::POR_GRUPO);
        $fotos = app(ImagenDeProducto::class)->porProductoCampana($publicaciones->pluck('id'));

        return $publicaciones->map(fn (ProductoCampana $pc) => [
            'titulo' => (string) $pc->producto?->nombre,
            'detalle' => trim(($pc->producto?->marca?->nombre ? $pc->producto->marca->nombre.' · ' : '').'Modelo '.$pc->producto?->modelo),
            'url' => route('catalogo.producto.detalle', $pc->producto_id),
            'imagen' => $fotos[$pc->id] ?? null,
        ])->values()->all();
    }

    /** Variantes con existencias en esta distribuidora (stock local con scope de tenant). */
    private function variantes(string $like): array
    {
        $filas = app(\App\Services\Stock\StockService::class)->consultar()
            ->with(['variante.producto', 'variante.talla', 'variante.color'])
            ->whereHas('variante', fn ($v) => $v->where('sku', 'like', $like)
                ->orWhereHas('producto', fn ($p) => $p->where('modelo', 'like', $like)))
            ->limit(self::POR_GRUPO)->get();
        $fotos = app(ImagenDeProducto::class)->porProducto($filas->map(fn (StockLocal $s) => $s->variante?->producto_id));

        return $filas->map(fn (StockLocal $s) => [
            'titulo' => (string) $s->variante?->sku,
            'detalle' => trim(($s->variante?->producto?->nombre ?? '').' · T. '.($s->variante?->talla?->valor ?? '—').' · '.($s->variante?->nombre_color_comercial ?: $s->variante?->color?->nombre ?? '').' · '.$s->cantidad_disponible.' disp.'),
            'url' => route('stock.index', ['busqueda' => (string) $s->variante?->sku]),
            'imagen' => $fotos[(int) ($s->variante?->producto_id ?? 0)] ?? null,
        ])->all();
    }
}
