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
    /** Distribuidoras visibles, por nombre; opcionalmente de una categoría. */
    public function distribuidoras(?int $categoriaId = null): Collection
    {
        return Distribuidora::query()
            ->where('distribuidoras.estado', 'activa')
            ->where('distribuidoras.marketplace_visible', true)
            ->when($categoriaId !== null, fn (Builder $q) => $q->whereHas(
                'categoriasDirectorio',
                fn (Builder $c) => $c->activas()->where('categorias_directorio.id', $categoriaId)
            ))
            ->with(['categoriasDirectorio' => fn ($c) => $c->activas()->orderBy('nombre')])
            ->orderBy('nombre_comercial')
            ->get();
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
