<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * La distribuidora empieza a vender una línea del catálogo (TG-210, TG-211).
 *
 * Lo llamará la pantalla "Mis líneas" (R6). Aquí solo está la regla: la línea
 * tiene que existir y estar activa en el catálogo maestro, y la distribuidora
 * tiene que tener lugar en su plan.
 *
 * Si ya no le queda lugar, puede activarla de todos modos pagando una línea
 * extra (E2-06), pero solo si lo confirma: la pantalla pregunta primero,
 * usando el mensaje que devuelve este mismo código.
 *
 * Activar una línea que ya tenía desactivada la reactiva: no se crea otra fila,
 * para no perder desde cuándo la vende.
 */
class ActivarLineaDistribuidoraAction
{
    public function __construct(
        private CupoLineasDistribuidora $cupo,
        private CobroSuscripcion $cobro,
        private RegistrarAuditoriaAction $auditoria,
    ) {}

    public function ejecutar(int $lineaId, bool $aceptaCargoExtra = false): DistribuidoraLinea
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

        return DB::transaction(function () use ($linea, $distribuidoraId, $aceptaCargoExtra) {
            $yaLaTiene = DistribuidoraLinea::where('linea_id', $linea->id)->lockForUpdate()->first();

            if ($yaLaTiene?->activa) {
                return $yaLaTiene;
            }

            $conCargoExtra = $this->cupo->disponibles() === 0;

            if ($conCargoExtra) {
                if (! $aceptaCargoExtra) {
                    throw new OperacionInvalidaException($this->mensajeDeCargoExtra(), 409);
                }

                $this->cobro->contratarUnaLineaExtra();
            }

            // Informativo: esta activación va por encima de las líneas
            // incluidas del plan. Lo que se cobra se cuenta aparte, en
            // CobroSuscripcion.
            $esExtra = $this->cupo->laSiguienteSeriaExtra();

            $suya = $yaLaTiene;

            if ($suya) {
                $suya->update([
                    'activa' => true,
                    'es_extra' => $esExtra,
                    'fecha_activacion' => now(),
                ]);
            } else {
                $suya = DistribuidoraLinea::create([
                    'distribuidora_id' => $distribuidoraId,
                    'linea_id' => $linea->id,
                    'es_extra' => $esExtra,
                    'activa' => true,
                    'fecha_activacion' => now(),
                ]);
            }

            if ($conCargoExtra) {
                $this->auditoria->ejecutar(
                    'linea_extra.activada',
                    'distribuidora_linea',
                    $suya->id,
                    null,
                    [
                        'linea' => $linea->nombre,
                        'precio_linea_extra' => $this->cobro->precioDeUnaLineaExtra(),
                        'monto_siguiente_cobro' => $this->cobro->montoDelSiguienteCobro(),
                    ]
                );
            }

            return $suya;
        });
    }

    /**
     * Lo que ve la persona antes de confirmar. Lleva el monto para que la
     * pantalla no tenga que calcularlo; si el plan no cobra las líneas extra,
     * no se habla de dinero.
     */
    private function mensajeDeCargoExtra(): string
    {
        $limite = $this->cupo->limite();
        $precio = $this->cobro->precioDeUnaLineaExtra();

        $aviso = "Ya tienes activas las {$limite} línea(s) que incluye tu plan. ";

        if ($precio <= 0) {
            return $aviso.'Esta línea queda por encima de tu plan. Confirma para activarla.';
        }

        return $aviso.'Activar otra cuesta $'.number_format($precio, 2).' más por periodo. Confirma para activarla.';
    }
}
