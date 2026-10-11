<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\Suscripcion;

/**
 * Cuántas líneas puede tener activas una distribuidora (TG-210).
 *
 * El único lugar donde se hace esta cuenta: lo usan activar y desactivar
 * líneas, y de aquí saldrá el cargo por línea extra (K4) y el contador de la
 * pantalla "Mis líneas" (R6).
 *
 * El límite es lo que dice su suscripción activa: las líneas incluidas en el
 * plan más las extra que haya contratado.
 */
class CupoLineasDistribuidora
{
    /** Líneas incluidas + extras contratadas de la suscripción activa. */
    public function limite(): int
    {
        $suscripcion = $this->suscripcionActiva();

        return (int) $suscripcion->lineas_incluidas_contratadas + (int) $suscripcion->lineas_extra_contratadas;
    }

    /** Las incluidas en el plan, sin contar las extra. */
    public function incluidasEnElPlan(): int
    {
        return (int) $this->suscripcionActiva()->lineas_incluidas_contratadas;
    }

    /**
     * Cuántas lleva ocupadas.
     *
     * Solo cuentan las que siguen activas en el catálogo maestro: si el admin
     * general retira una línea, deja de ocupar lugar del plan.
     */
    public function activas(): int
    {
        return DistribuidoraLinea::query()->vigentes()->count();
    }

    public function disponibles(): int
    {
        return max(0, $this->limite() - $this->activas());
    }

    /** La siguiente activación, ¿va por encima de las incluidas del plan? */
    public function laSiguienteSeriaExtra(): bool
    {
        return $this->activas() >= $this->incluidasEnElPlan();
    }

    public function asegurarQueCabeUnaMas(): void
    {
        if ($this->disponibles() > 0) {
            return;
        }

        $limite = $this->limite();

        throw new OperacionInvalidaException(
            "Tu plan permite {$limite} línea(s) activa(s) y ya las tienes todas. "
            .'Desactiva una línea o pide ampliar tu plan para activar otra.',
            409
        );
    }

    private function suscripcionActiva(): Suscripcion
    {
        $suscripcion = Suscripcion::where('estado', 'activa')->latest('id')->first();

        if (! $suscripcion) {
            throw new OperacionInvalidaException(
                'Tu distribuidora no tiene una suscripción activa, así que no se pueden activar líneas.',
                409
            );
        }

        return $suscripcion;
    }
}
