<?php

namespace App\Observers;

use App\Models\Campana;
use App\Models\CicloCompra;
use App\Models\Color;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Talla;
use App\Models\Temporada;
use App\Support\Cache\CatalogoCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Limpia App\Support\Cache\CatalogoCache cuando cambia un catálogo.
 * Se registra en AppServiceProvider para saved/deleted (y restored).
 */
class InvalidarCatalogoCache
{
    public function saved(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    public function deleted(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    public function restored(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    private function invalidar(Model $modelo): void
    {
        match (true) {
            $modelo instanceof Talla, $modelo instanceof Color => CatalogoCache::olvidarGlobal('tallas_colores'),
            $modelo instanceof Marca => CatalogoCache::olvidarGlobal('marcas'),
            $modelo instanceof Campana => CatalogoCache::olvidarDelTenant(CatalogoCache::nombreTemporadasFiltro(), $this->distribuidorasDeLineas([
                $modelo->linea_id, $modelo->getOriginal('linea_id'),
            ])),
            $modelo instanceof Temporada => CatalogoCache::olvidarGlobal(CatalogoCache::nombreTemporadasFiltro()),
            $modelo instanceof Linea => CatalogoCache::olvidarDelTenant(CatalogoCache::nombreTemporadasFiltro(), $this->distribuidorasDeLineas([$modelo->id])),
            $modelo instanceof DistribuidoraLinea => CatalogoCache::olvidarDelTenant(CatalogoCache::nombreTemporadasFiltro(), [$modelo->distribuidora_id]),
            $modelo instanceof CicloCompra => CatalogoCache::olvidarDelTenant('ciclos', [$modelo->distribuidora_id, $modelo->getOriginal('distribuidora_id')]),
            default => null,
        };
    }

    /** Distribuidoras que tienen o tuvieron alguna de esas líneas (sin filtro de tenant). */
    private function distribuidorasDeLineas(array $lineaIds): array
    {
        $lineaIds = array_values(array_filter($lineaIds));

        if ($lineaIds === []) {
            return [];
        }

        return DistribuidoraLinea::query()->withoutGlobalScopes()
            ->whereIn('linea_id', $lineaIds)->pluck('distribuidora_id')->unique()->all();
    }
}
