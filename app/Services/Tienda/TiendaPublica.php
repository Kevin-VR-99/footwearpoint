<?php

namespace App\Services\Tienda;

use App\Models\Campana;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoDestacado;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use App\Support\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TG-233 (G14) — Lo que se ve en la tienda pública de una distribuidora
 * (/tienda/{slug}). Es pública: la puede abrir cualquiera, con o sin sesión.
 *
 * Qué vende la distribuidora lo decide CatalogoVisible y el precio,
 * PrecioEfectivo. Aquí solo se agrega lo propio de la tienda:
 *
 *   - solo distribuidoras activas; cualquier otra (o un slug que no existe)
 *     es un 404, sin decir por qué;
 *   - productos y tallas activos, y solo productos con al menos una talla
 *     que se pueda pedir (disponible o bajo pedido);
 *   - un solo precio: el de menudeo. El otro precio nunca sale de aquí.
 *
 * Todo corre dentro de Tenant::forzar con la distribuidora de la tienda: sin
 * sesión el scope de tenant no filtra (se verían las líneas de TODAS), y con
 * sesión filtraría por la distribuidora de quien la abre, no por la de la
 * tienda. Lo que sale de aquí son arreglos ya armados, para que la vista no
 * consulte nada fuera de ese contexto.
 */
class TiendaPublica
{
    public const POR_PAGINA = 24;

    public const MAX_DESTACADOS = 8;

    public function __construct(
        private readonly CatalogoVisible $catalogo,
        private readonly PrecioEfectivo $precios,
    ) {
    }

    /** La distribuidora de esa tienda, si está activa. Si no, 404. */
    public function distribuidora(string $slug): Distribuidora
    {
        $distribuidora = preg_match('/^[a-z0-9-]{1,120}$/', $slug) === 1
            ? Distribuidora::query()->where('slug', $slug)->where('estado', 'activa')->first()
            : null;

        abort_if($distribuidora === null, 404);

        return $distribuidora;
    }

    /**
     * Los productos de la tienda, por nombre y paginados.
     *
     * @param  array{marca?: int|null, linea?: int|null, busqueda?: string|null}  $filtros
     */
    public function catalogo(Distribuidora $distribuidora, array $filtros = []): LengthAwarePaginator
    {
        return $this->enLaTienda($distribuidora, function () use ($filtros) {
            $moneda = $this->moneda();

            return $this->conRelaciones($this->consulta())
                ->when($filtros['marca'] ?? null, fn (Builder $q, int $marca) => $q
                    ->whereHas('producto', fn (Builder $p) => $p->where('marca_id', $marca)))
                ->when($filtros['linea'] ?? null, fn (Builder $q, int $linea) => $q
                    ->whereHas('campana', fn (Builder $c) => $c->where('linea_id', $linea)))
                ->when($this->textoDeBusqueda($filtros['busqueda'] ?? null), fn (Builder $q, string $texto) => $this->buscar($q, $texto))
                ->orderBy(Producto::query()->select('nombre')->whereColumn('productos.id', 'producto_campana.producto_id'))
                ->orderBy('producto_campana.id')
                ->paginate(self::POR_PAGINA)
                ->through(fn (ProductoCampana $producto) => $this->ficha($producto, $moneda));
        });
    }

    /** Un producto de la tienda. Si no lo vende (o no existe), 404. */
    public function producto(Distribuidora $distribuidora, int $productoCampanaId): array
    {
        return $this->enLaTienda($distribuidora, function () use ($productoCampanaId) {
            $producto = $this->conRelaciones($this->consulta())->whereKey($productoCampanaId)->first();

            abort_if($producto === null, 404);

            return $this->ficha($producto, $this->moneda());
        });
    }

    /** Los destacados que eligió la distribuidora, en su orden (si los hay). */
    public function destacados(Distribuidora $distribuidora): Collection
    {
        return $this->enLaTienda($distribuidora, function () {
            $ids = ProductoDestacado::query()
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('id')
                ->pluck('producto_campana_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids === []) {
                return collect();
            }

            $moneda = $this->moneda();
            $productos = $this->conRelaciones($this->consulta())
                ->whereIn('producto_campana.id', $ids)
                ->get()
                ->keyBy('id');

            // En el orden que eligió la distribuidora; los que ya no vende se saltan.
            return collect($ids)
                ->filter(fn (int $id) => $productos->has($id))
                ->take(self::MAX_DESTACADOS)
                ->map(fn (int $id) => $this->ficha($productos[$id], $moneda))
                ->values();
        });
    }

    /** Las marcas de lo que vende, por nombre. */
    public function marcas(Distribuidora $distribuidora): Collection
    {
        return $this->enLaTienda($distribuidora, fn () => Marca::query()
            ->whereIn('id', Producto::query()->select('marca_id')
                ->whereIn('id', $this->consulta()->select('producto_campana.producto_id')))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'logotipo_url'])
            ->map(fn (Marca $marca) => [
                'id'       => (int) $marca->id,
                'nombre'   => $marca->nombre,
                'logotipo' => $marca->logotipo_url,
            ]));
    }

    /** Las líneas de lo que vende, por nombre. */
    public function lineas(Distribuidora $distribuidora): Collection
    {
        return $this->enLaTienda($distribuidora, fn () => Linea::query()
            ->whereIn('id', Campana::query()->select('linea_id')
                ->whereIn('id', $this->consulta()->select('producto_campana.campana_id')))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'logotipo_url'])
            ->map(fn (Linea $linea) => [
                'id'       => (int) $linea->id,
                'nombre'   => $linea->nombre,
                'logotipo' => $linea->logotipo_url,
            ]));
    }

    // ---------------------------------------------------------------

    /**
     * Todo lo de la tienda se consulta como si fuera esta distribuidora,
     * aunque quien la abre no tenga sesión o sea de otra.
     */
    private function enLaTienda(Distribuidora $distribuidora, callable $consulta): mixed
    {
        return Tenant::forzar((int) $distribuidora->id, $consulta);
    }

    /** Lo que la distribuidora vende y se puede pedir. Solo dentro de enLaTienda(). */
    private function consulta(): Builder
    {
        return $this->catalogo->consulta()
            ->whereHas('producto', fn (Builder $p) => $p->where('activo', true))
            ->whereHas('disponibilidadPorVariante', fn (Builder $d) => $this->tallasQueSePiden($d));
    }

    private function conRelaciones(Builder $consulta): Builder
    {
        return $consulta->with([
            'producto.marca',
            'producto.categoria',
            'campana.linea',
            'imagenes' => fn ($i) => $i->orderByDesc('es_principal')->orderBy('orden')->orderBy('id'),
            'disponibilidadPorVariante' => fn ($d) => $this->tallasQueSePiden($d),
            'disponibilidadPorVariante.variante.talla',
            'disponibilidadPorVariante.variante.color',
        ]);
    }

    /** Tallas activas que se pueden pedir (disponible o bajo pedido). */
    private function tallasQueSePiden($disponibilidad)
    {
        return $disponibilidad
            ->whereIn('estado', DisponibilidadVarianteCampana::ESTADOS_QUE_SE_PUEDEN_PEDIR)
            ->whereHas('variante', fn (Builder $v) => $v->where('activa', true));
    }

    private function textoDeBusqueda(?string $texto): ?string
    {
        $texto = trim(mb_substr((string) $texto, 0, 100));

        return $texto === '' ? null : $texto;
    }

    /** Por nombre, modelo, código de catálogo o marca. */
    private function buscar(Builder $consulta, string $texto): Builder
    {
        $patron = '%'.addcslashes($texto, '\\%_').'%';

        return $consulta->where(fn (Builder $q) => $q
            ->where('producto_campana.codigo_catalogo', 'like', $patron)
            ->orWhereHas('producto', fn (Builder $p) => $p
                ->where('nombre', 'like', $patron)
                ->orWhere('modelo', 'like', $patron)
                ->orWhereHas('marca', fn (Builder $m) => $m->where('nombre', 'like', $patron))));
    }

    private function moneda(): string
    {
        $moneda = ConfiguracionDistribuidora::query()->value('moneda');

        return is_string($moneda) && $moneda !== '' ? $moneda : 'MXN';
    }

    /** Lo que la vista necesita de un producto, con su precio de menudeo. */
    private function ficha(ProductoCampana $producto, string $moneda): array
    {
        $precio = $this->precios->menudeo($producto);

        $tallas = $producto->disponibilidadPorVariante
            ->filter(fn (DisponibilidadVarianteCampana $d) => $d->variante?->talla !== null)
            ->sortBy(fn (DisponibilidadVarianteCampana $d) => [
                (int) ($d->variante->talla->orden ?? 0),
                (float) $d->variante->talla->valor,
            ])
            ->map(fn (DisponibilidadVarianteCampana $d) => [
                'talla'       => (string) $d->variante->talla->valor,
                'color'       => $d->variante->nombre_color_comercial ?: $d->variante->color?->nombre,
                'bajo_pedido' => $d->estado === 'bajo_pedido',
            ])
            ->values();

        $imagenes = $producto->imagenes->pluck('url')->filter()->values();

        return [
            'id'          => (int) $producto->id,
            'nombre'      => $producto->producto?->nombre,
            'modelo'      => $producto->producto?->modelo,
            'descripcion' => $producto->producto?->descripcion,
            'marca'       => $producto->producto?->marca?->nombre,
            'categoria'   => $producto->producto?->categoria?->nombre,
            'linea'       => $producto->campana?->linea?->nombre,
            'temporada'   => $producto->campana?->nombre,
            'codigo'      => $producto->codigo_catalogo,
            'precio'      => $precio,
            'precio_texto' => '$'.number_format($precio, 2).' '.$moneda,
            'imagen'      => $imagenes->first(),
            'imagenes'    => $imagenes->all(),
            'colores'     => $tallas->pluck('color')->filter()->unique()->values()->all(),
            'tallas'      => $tallas->all(),
            'hay_bajo_pedido' => $tallas->contains('bajo_pedido', true),
        ];
    }
}
