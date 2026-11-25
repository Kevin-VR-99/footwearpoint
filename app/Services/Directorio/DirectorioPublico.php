<?php

namespace App\Services\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as Consulta;
use Illuminate\Support\Collection as Lista;
use Illuminate\Support\Facades\DB;

/**
 * TG-197 (G16) — Lo que se ve en el directorio público (marketplace).
 *
 * Lo usan la página /marketplace y GET /api/marketplace(/categorias), para
 * que las dos muestren exactamente lo mismo:
 *  - Distribuidoras activas y marcadas como visibles por el admin general.
 *  - Solo categorías activas, y en los filtros solo las que tienen al menos
 *    una distribuidora visible.
 */
class DirectorioPublico
{
    /**
     * Distribuidoras visibles, por nombre.
     *
     * La categoría sigue disponible para la API. El marketplace público
     * filtra por nombre y por lugar (la dirección pública), no por categoría.
     *
     * TG-391 (A31): y por línea del catálogo (Cklass, Impuls...), la que el
     * visitante busca de verdad. Cuenta solo la que la distribuidora tiene
     * activa y que sigue activa en el catálogo maestro.
     */
    public function distribuidoras(?int $categoriaId = null, ?string $nombre = null, ?string $lugar = null, ?string $termino = null, ?int $lineaId = null): Collection
    {
        $nombre = $this->texto($nombre);
        $lugar = $this->texto($lugar);
        $palabras = $this->palabras($termino);

        return Distribuidora::query()
            ->where('distribuidoras.estado', 'activa')
            ->where('distribuidoras.marketplace_visible', true)
            ->when($categoriaId !== null, fn (Builder $q) => $q->whereHas(
                'categoriasDirectorio',
                fn (Builder $c) => $c->activas()->where('categorias_directorio.id', $categoriaId)
            ))
            ->when($nombre !== null, fn (Builder $q) => $q->where(
                'nombre_comercial', 'like', '%'.$this->comoLike($nombre).'%'
            ))
            ->when($lugar !== null, fn (Builder $q) => $q->where(
                'direccion_publica', 'like', '%'.$this->comoLike($lugar).'%'
            ))
            // Buscador unificado: cada palabra debe aparecer en el nombre, la dirección pública
            // (ciudad, colonia, calle) o, si no hay dirección pública, en la de la sucursal principal.
            ->when($palabras !== [], function (Builder $q) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $like = '%'.$this->comoLike($palabra).'%';
                    $q->where(fn (Builder $o) => $o
                        ->where('distribuidoras.nombre_comercial', 'like', $like)
                        ->orWhere('distribuidoras.direccion_publica', 'like', $like)
                        ->orWhereExists(fn ($s) => $s->selectRaw('1')
                            ->from('sucursales')
                            ->whereColumn('sucursales.distribuidora_id', 'distribuidoras.id')
                            ->where('sucursales.es_principal', true)
                            ->where('sucursales.activa', true)
                            ->where(fn ($d) => $d->whereNull('distribuidoras.direccion_publica')->orWhere('distribuidoras.direccion_publica', ''))
                            ->where('sucursales.direccion', 'like', $like)));
                }
            })
            ->when($lineaId !== null, fn (Builder $q) => $q->whereExists(
                fn (Consulta $s) => $this->lineasVigentes($s->selectRaw('1'))
                    ->whereColumn('distribuidora_linea.distribuidora_id', 'distribuidoras.id')
                    ->where('distribuidora_linea.linea_id', $lineaId)
            ))
            ->with(['categoriasDirectorio' => fn ($c) => $c->activas()->orderBy('nombre')])
            ->orderBy('nombre_comercial')
            ->get();
    }

    /**
     * TG-391 (A31) — Las líneas que vende al menos una distribuidora visible,
     * por nombre, con cuántas la venden. Son los filtros del marketplace.
     *
     * Va con el constructor de consultas y no con DistribuidoraLinea a
     * propósito: ese modelo lleva el scope de tenant, y con sesión de una
     * distribuidora filtraría por la suya y el directorio público saldría
     * distinto según quién lo abre.
     *
     * @return Lista<int, object{id: int, nombre: string, logotipo_url: ?string, total: int}>
     */
    public function lineas(): Lista
    {
        return $this->lineasVigentes(DB::table('distribuidora_linea'))
            ->join('distribuidoras', 'distribuidoras.id', '=', 'distribuidora_linea.distribuidora_id')
            ->where('distribuidoras.estado', 'activa')
            ->where('distribuidoras.marketplace_visible', true)
            ->groupBy('lineas.id', 'lineas.nombre', 'lineas.logotipo_url')
            ->orderBy('lineas.nombre')
            ->get([
                'lineas.id',
                'lineas.nombre',
                'lineas.logotipo_url',
                DB::raw('COUNT(DISTINCT distribuidora_linea.distribuidora_id) as total'),
            ])
            ->map(fn ($l) => (object) [
                'id'           => (int) $l->id,
                'nombre'       => (string) $l->nombre,
                'logotipo_url' => $l->logotipo_url,
                'total'        => (int) $l->total,
            ]);
    }

    /**
     * TG-391 (A31) — Las líneas vigentes de cada distribuidora, en una sola
     * consulta para toda la página (sin N+1). Mismo motivo que lineas() para
     * no usar el modelo con scope de tenant.
     *
     * @param  iterable<int>  $distribuidoraIds
     * @return array<int, list<object{id: int, nombre: string, logotipo_url: ?string}>>
     */
    public function lineasPorDistribuidora(iterable $distribuidoraIds): array
    {
        $ids = collect($distribuidoraIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->lineasVigentes(DB::table('distribuidora_linea'))
            ->whereIn('distribuidora_linea.distribuidora_id', $ids->all())
            ->orderBy('lineas.nombre')
            ->get(['distribuidora_linea.distribuidora_id', 'lineas.id', 'lineas.nombre', 'lineas.logotipo_url'])
            ->groupBy('distribuidora_id')
            ->map(fn (Lista $filas) => $filas->map(fn ($l) => (object) [
                'id'           => (int) $l->id,
                'nombre'       => (string) $l->nombre,
                'logotipo_url' => $l->logotipo_url,
            ])->values()->all())
            ->all();
    }

    /** distribuidora_linea activa y con su línea activa en el catálogo maestro. */
    private function lineasVigentes(Consulta $consulta): Consulta
    {
        if ($consulta->from === null) {
            $consulta->from('distribuidora_linea');
        }

        return $consulta
            ->join('lineas', 'lineas.id', '=', 'distribuidora_linea.linea_id')
            ->where('distribuidora_linea.activa', true)
            ->where('lineas.activa', true);
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /** @return list<string> Palabras del buscador unificado (sin vacías, máximo 8). */
    private function palabras(?string $termino): array
    {
        $partes = preg_split('/[\s,]+/u', trim((string) $termino), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($partes)), 0, 8);
    }

    /** Evita que % y _ del texto se vuelvan comodines del LIKE. */
    private function comoLike(string $valor): string
    {
        return addcslashes($valor, '%_\\');
    }

    /** Categorías activas con al menos una distribuidora visible, por nombre. */
    public function categorias(): Collection
    {
        $visibles = fn (Builder $d) => $d
            ->where('distribuidoras.estado', 'activa')
            ->where('distribuidoras.marketplace_visible', true);

        return CategoriaDirectorio::query()
            ->activas()
            ->whereHas('distribuidoras', $visibles)
            ->withCount(['distribuidoras as total_distribuidoras' => $visibles])
            ->orderBy('nombre')
            ->get();
    }
}
