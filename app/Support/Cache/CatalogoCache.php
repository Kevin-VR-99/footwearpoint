<?php

namespace App\Support\Cache;

use App\Models\Campana;
use App\Models\CicloCompra;
use App\Models\Color;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Marca;
use App\Models\Talla;
use App\Support\Tenant;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de catálogos que cambian poco (tallas, colores, marcas, temporadas
 * del filtro, ciclos), SIEMPRE con la distribuidora en la llave:
 *
 *   catalogo:{distribuidora_id|0}:{nombre}
 *
 * - Usa el store configurado (CACHE_STORE; database en Railway) envuelto en
 *   Cache::memo(), así que dentro de una misma petición cada llave se lee
 *   una sola vez.
 * - Se invalida desde App\Observers\InvalidarCatalogoCache al guardar o
 *   borrar el modelo: los catálogos globales (Talla, Color, Marca) limpian
 *   la llave de TODAS las distribuidoras; los del tenant (Campana por línea,
 *   DistribuidoraLinea, CicloCompra) solo las de su distribuidora.
 * - Guarda arreglos simples (no modelos) para que el valor sea pequeño y no
 *   dependa de la serialización de Eloquent.
 */
class CatalogoCache
{
    public const TTL_SEGUNDOS = 21600; // 6 h; la invalidación por eventos es la que manda.

    /** Catálogos globales: se repiten por distribuidora para que la llave siempre la lleve. */
    public const GLOBALES = ['tallas_colores', 'marcas'];

    /** Catálogos propios de cada distribuidora. */
    public const DEL_TENANT = ['temporadas_filtro', 'ciclos'];

    public static function llave(?int $distribuidoraId, string $nombre): string
    {
        return 'catalogo:'.($distribuidoraId ?? 0).':'.$nombre;
    }

    /** @return Collection<int, array<string, mixed>> atributos de cada talla, por id */
    public function tallas(): Collection
    {
        return collect($this->tallasYColores()['tallas']);
    }

    /** @return Collection<int, array<string, mixed>> atributos de cada color, por id */
    public function colores(): Collection
    {
        return collect($this->tallasYColores()['colores']);
    }

    /**
     * Tallas y colores viajan juntos en UNA llave: con CACHE_STORE=database
     * cada lectura es una consulta a la tabla cache, así que una sola lectura
     * reemplaza las dos consultas de with('variante.talla', 'variante.color').
     */
    private function tallasYColores(): array
    {
        return $this->recordar('tallas_colores', fn () => [
            'tallas' => Talla::query()->orderBy('id')->get()->mapWithKeys(fn (Talla $t) => [$t->id => $t->getAttributes()])->all(),
            'colores' => Color::query()->orderBy('id')->get()->mapWithKeys(fn (Color $c) => [$c->id => $c->getAttributes()])->all(),
        ]);
    }

    /** Modelos Talla (sin consulta) para los ids pedidos, por id. */
    public function modelosTalla(iterable $ids): Collection
    {
        return $this->hidratar(Talla::class, $this->tallas(), $ids);
    }

    /** Modelos Color (sin consulta) para los ids pedidos, por id. */
    public function modelosColor(iterable $ids): Collection
    {
        return $this->hidratar(Color::class, $this->colores(), $ids);
    }

    /**
     * Pone las relaciones talla y color de cada variante desde la caché, en
     * lugar de with('variante.talla', 'variante.color') (dos consultas menos).
     */
    public function asignarTallaYColor(iterable $variantes): void
    {
        $variantes = collect($variantes)->filter();
        $tallas = $this->modelosTalla($variantes->pluck('talla_id'));
        $colores = $this->modelosColor($variantes->pluck('color_id'));

        foreach ($variantes as $variante) {
            $variante->setRelation('talla', $tallas->get((int) $variante->talla_id));
            $variante->setRelation('color', $colores->get((int) $variante->color_id));
        }
    }

    /** @return Collection<int, object{id:int, nombre:string}> en orden alfabético */
    public function marcas(): Collection
    {
        return collect($this->recordar('marcas', fn () => Marca::query()->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Marca $m) => ['id' => $m->id, 'nombre' => $m->nombre])->all()))
            ->map(fn (array $m) => (object) $m);
    }

    /**
     * Temporadas para el filtro de reportes: las de líneas que la distribuidora
     * tiene o tuvo, con el nombre de su línea.
     *
     * @return Collection<int, object{id:int, nombre:string, linea_id:int, linea:?object}>
     */
    public function temporadasParaFiltro(): Collection
    {
        return collect($this->recordar('temporadas_filtro', fn () => Campana::query()
            ->with('linea:id,nombre')
            ->whereIn('linea_id', DistribuidoraLinea::query()->select('linea_id'))
            ->orderByDesc('fecha_inicio')->orderBy('nombre')
            ->get(['id', 'nombre', 'linea_id'])
            ->map(fn (Campana $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'linea_id' => $c->linea_id,
                'linea' => $c->linea ? ['id' => $c->linea->id, 'nombre' => $c->linea->nombre] : null,
            ])->all()))
            ->map(function (array $c) {
                $c['linea'] = $c['linea'] !== null ? (object) $c['linea'] : null;

                return (object) $c;
            });
    }

    /** @return Collection<int, object{id:int, nombre:string}> más reciente primero */
    public function ciclos(): Collection
    {
        return collect($this->recordar('ciclos', fn () => CicloCompra::query()->orderByDesc('fecha_apertura')
            ->get(['id', 'nombre'])->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->all()))
            ->map(fn (array $c) => (object) $c);
    }

    // ---- invalidación ----

    /** Borra un catálogo global en todas las distribuidoras (y en la llave 0). */
    public static function olvidarGlobal(string $nombre): void
    {
        foreach (self::idsDeDistribuidoras() as $id) {
            self::store()->forget(self::llave($id, $nombre));
        }
    }

    /** Borra un catálogo del tenant solo para esas distribuidoras. */
    public static function olvidarDelTenant(string $nombre, iterable $distribuidoraIds): void
    {
        foreach (collect($distribuidoraIds)->filter()->unique() as $id) {
            self::store()->forget(self::llave((int) $id, $nombre));
        }
    }

    // ---- internos ----

    private function recordar(string $nombre, Closure $calcular): mixed
    {
        return self::store()->remember(self::llave(Tenant::id(), $nombre), self::TTL_SEGUNDOS, $calcular);
    }

    private static function store()
    {
        return Cache::memo();
    }

    /** @return list<int> todas las distribuidoras + 0 (sin sesión / admin general) */
    private static function idsDeDistribuidoras(): array
    {
        return [0, ...Distribuidora::query()->withoutGlobalScopes()->pluck('id')->map(fn ($id) => (int) $id)->all()];
    }

    private function hidratar(string $clase, Collection $filas, iterable $ids): Collection
    {
        return collect($ids)->filter()->unique()
            ->mapWithKeys(fn ($id) => isset($filas[(int) $id]) ? [(int) $id => (new $clase)->newFromBuilder($filas[(int) $id])] : [])
            ;
    }
}
