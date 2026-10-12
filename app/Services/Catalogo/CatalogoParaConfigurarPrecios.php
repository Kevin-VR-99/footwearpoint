<?php

namespace App\Services\Catalogo;

use App\Models\OfertaDistribuidora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los productos que la distribuidora puede configurar en "Precios y
 * publicación" (TG-249, E4-14): los de sus líneas activas en temporadas
 * activas, INCLUYENDO los que ocultó, para que pueda volver a mostrarlos.
 *
 * Cada producto trae su oferta (precio propio y si está publicado) ya
 * cargada, y los precios se calculan con PrecioEfectivo.
 */
class CatalogoParaConfigurarPrecios
{
    public const FILTRO_TODOS = 'todos';

    public const FILTRO_OCULTOS = 'ocultos';

    public const FILTRO_CON_PRECIO_PROPIO = 'precio_propio';

    public function __construct(
        private CatalogoVisible $catalogo,
        private PrecioEfectivo $precios,
    ) {
    }

    public function consulta(string $busqueda = '', string $filtro = self::FILTRO_TODOS): Builder
    {
        $consulta = $this->catalogo->consultaIncluyendoOcultos()
            ->with(['producto.marca', 'campana.linea']);

        $termino = trim($busqueda);

        if ($termino !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termino).'%';

            $consulta->where(fn (Builder $q) => $q
                ->where('codigo_catalogo', 'like', $like)
                ->orWhereHas('producto', fn (Builder $p) => $p
                    ->where('nombre', 'like', $like)
                    ->orWhere('modelo', 'like', $like)
                    ->orWhereHas('marca', fn (Builder $m) => $m->where('nombre', 'like', $like))
                )
            );
        }

        if ($filtro === self::FILTRO_OCULTOS) {
            $consulta->whereIn('producto_campana.id', OfertaDistribuidora::query()
                ->where('publicado', false)->select('producto_campana_id'));
        } elseif ($filtro === self::FILTRO_CON_PRECIO_PROPIO) {
            $consulta->whereIn('producto_campana.id', OfertaDistribuidora::query()
                ->whereNotNull('precio_mayorista')->select('producto_campana_id'));
        }

        return $consulta->orderBy('producto_campana.id');
    }

    /**
     * Una página de productos, cada uno con 'oferta' (o null), y los precios
     * propios ya precargados en PrecioEfectivo.
     */
    public function pagina(string $busqueda = '', string $filtro = self::FILTRO_TODOS, int $porPagina = 25): LengthAwarePaginator
    {
        $pagina = $this->consulta($busqueda, $filtro)->paginate($porPagina);

        $ofertas = OfertaDistribuidora::query()
            ->whereIn('producto_campana_id', $pagina->getCollection()->pluck('id'))
            ->get()
            ->keyBy('producto_campana_id');

        $pagina->getCollection()->each(fn ($pc) => $pc->setRelation('oferta', $ofertas->get($pc->id)));

        $this->precios->olvidarLoConsultado();
        $this->precios->precargar($pagina->getCollection());

        return $pagina;
    }
}
