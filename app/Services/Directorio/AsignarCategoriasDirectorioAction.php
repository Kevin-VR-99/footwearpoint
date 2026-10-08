<?php

namespace App\Services\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-197 (G16) — Definir en qué categorías del directorio aparece una
 * distribuidora. Solo lo hace el admin general (E2-05).
 *
 * Es la única lógica para esto: la usan "Ver datos" del panel del admin
 * general y PUT /api/admin/distribuidoras/{id}/categorias-directorio.
 *
 * La lista que llega reemplaza a las categorías ACTIVAS que tenía. Las
 * inactivas que ya tenía se conservan (no se ofrecen al elegir, así que no
 * se pueden quitar por accidente) y vuelven a verse si se reactivan.
 */
class AsignarCategoriasDirectorioAction
{
    public const MENSAJE_NO_DISPONIBLE = 'Una de las categorías ya no está disponible. Recarga la página e intenta de nuevo.';

    /**
     * @param  array<int|string>  $categoriaIds
     * @return Collection<int, CategoriaDirectorio> sus categorías, por nombre.
     *
     * @throws ValidationException (campo 'categorias') si alguna no existe o está inactiva.
     */
    public function ejecutar(Distribuidora $distribuidora, array $categoriaIds): Collection
    {
        $ids = collect($categoriaIds)
            ->map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT))
            ->unique()
            ->values();

        if ($ids->contains(false)) {
            throw ValidationException::withMessages(['categorias' => [self::MENSAJE_NO_DISPONIBLE]]);
        }

        $activas = CategoriaDirectorio::activas()->whereIn('id', $ids)->pluck('id');

        if ($activas->count() !== $ids->count()) {
            throw ValidationException::withMessages(['categorias' => [self::MENSAJE_NO_DISPONIBLE]]);
        }

        DB::transaction(function () use ($distribuidora, $activas) {
            $inactivasQueTenia = $distribuidora->categoriasDirectorio()
                ->where('categorias_directorio.activa', false)
                ->pluck('categorias_directorio.id');

            $distribuidora->categoriasDirectorio()->sync($activas->merge($inactivasQueTenia)->unique()->all());
        });

        return $distribuidora->categoriasDirectorio()->orderBy('nombre')->get();
    }
}
