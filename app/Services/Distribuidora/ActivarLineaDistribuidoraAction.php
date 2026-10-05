<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * La distribuidora empieza a vender una línea del catálogo (TG-210).
 *
 * Lo llamará la pantalla "Mis líneas" (R6). Aquí solo está la regla: la línea
 * tiene que existir y estar activa en el catálogo maestro, y la distribuidora
 * no puede pasarse del límite de su plan.
 *
 * Activar una línea que ya tenía desactivada la reactiva: no se crea otra fila,
 * para no perder desde cuándo la vende.
 */
class ActivarLineaDistribuidoraAction
{
    public function __construct(private CupoLineasDistribuidora $cupo) {}

    public function ejecutar(int $lineaId): DistribuidoraLinea
    {
        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');

        $linea = Linea::find($lineaId);

        if (! $linea) {
            throw new OperacionInvalidaException('Esa línea no existe en el catálogo.', 404);
        }

        if (! $linea->activa) {
            throw new OperacionInvalidaException(
                "La línea \"{$linea->nombre}\" no está disponible en el catálogo en este momento.",
                409
            );
        }

        return DB::transaction(function () use ($linea, $distribuidoraId) {
            $yaLaTiene = DistribuidoraLinea::where('linea_id', $linea->id)->lockForUpdate()->first();

            if ($yaLaTiene?->activa) {
                return $yaLaTiene;
            }

            $this->cupo->asegurarQueCabeUnaMas();

            // Informativo: esta activación va por encima de las líneas
            // incluidas del plan (ver la nota de la migración).
            $esExtra = $this->cupo->laSiguienteSeriaExtra();

            if ($yaLaTiene) {
                $yaLaTiene->update([
                    'activa' => true,
                    'es_extra' => $esExtra,
                    'fecha_activacion' => now(),
                ]);

                return $yaLaTiene;
            }

            return DistribuidoraLinea::create([
                'distribuidora_id' => $distribuidoraId,
                'linea_id' => $linea->id,
                'es_extra' => $esExtra,
                'activa' => true,
                'fecha_activacion' => now(),
            ]);
        });
    }
}
