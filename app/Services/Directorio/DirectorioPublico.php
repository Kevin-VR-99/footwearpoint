<?php

namespace App\Services\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
     */
    public function distribuidoras(?int $categoriaId = null, ?string $nombre = null, ?string $lugar = null, ?string $termino = null): Collection
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
            ->with(['categoriasDirectorio' => fn ($c) => $c->activas()->orderBy('nombre')])
            ->orderBy('nombre_comercial')
            ->get();
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
