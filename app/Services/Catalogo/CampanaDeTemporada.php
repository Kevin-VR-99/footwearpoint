<?php

namespace App\Services\Catalogo;

use App\Models\Campana;
use App\Models\Temporada;
use App\Services\Auditoria\AuditarCambios;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Temporadas globales para el catálogo maestro y las importaciones.
 *
 * - Las temporadas que se pueden elegir al importar son la actual y la
 *   siguiente: el admin carga el catálogo de la próxima por adelantado y se
 *   queda oculto hasta que llegue su fecha.
 * - La campaña de una línea en una temporada se crea sola la primera vez
 *   (nunca a mano) y queda en la auditoría.
 */
class CampanaDeTemporada
{
    public function __construct(private AuditarCambios $auditar)
    {
    }

    /** @return Collection<int, Temporada> actual y siguiente, en ese orden */
    public function elegibles(): Collection
    {
        return collect([Temporada::actual(), Temporada::siguiente()]);
    }

    /** La temporada elegible con ese id; si no viene o no es elegible, la actual. */
    public function elegibleOActual(?int $temporadaId): Temporada
    {
        return $this->elegibles()->firstWhere('id', $temporadaId) ?? Temporada::actual();
    }

    public function esElegible(?int $temporadaId): bool
    {
        return $temporadaId !== null && $this->elegibles()->contains('id', $temporadaId);
    }

    /** La campaña de esa línea en esa temporada; la crea si no existe. */
    public function paraLinea(int $lineaId, Temporada $temporada): Campana
    {
        $existente = Campana::query()->where('linea_id', $lineaId)->where('temporada_id', $temporada->id)->first();

        if ($existente) {
            return $existente;
        }

        try {
            $campana = Campana::create([
                'linea_id' => $lineaId,
                'temporada_id' => $temporada->id,
                'nombre' => $temporada->nombre,
                'fecha_inicio' => $temporada->fecha_inicio,
                'fecha_fin' => $temporada->fecha_fin,
                'estado' => 'borrador',
            ]);
        } catch (QueryException) {
            // Otra petición la creó al mismo tiempo (índice único línea + temporada).
            return Campana::query()->where('linea_id', $lineaId)->where('temporada_id', $temporada->id)->firstOrFail();
        }

        $this->auditar->creado($campana, 'catalogo.temporada.creada_automaticamente', 'campana');

        return $campana;
    }
}
