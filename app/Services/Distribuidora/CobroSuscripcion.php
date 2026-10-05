<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Suscripcion;
use App\Services\Auditoria\RegistrarAuditoriaAction;

/**
 * Lo que le toca pagar a la distribuidora por su suscripción (E2-06, TG-211).
 *
 * El cobro tiene dos partes: el precio base de su plan y las líneas que tiene
 * activas por encima de las que incluye el plan. Esas líneas de más se cobran
 * aparte, al precio por línea extra que se le contrató.
 *
 * Es el único lugar donde se hace esta cuenta. Lo usa la activación de líneas
 * (K3/K4) y lo usará el cobro con Mercado Pago (G11).
 */
class CobroSuscripcion
{
    public function __construct(
        private CupoLineasDistribuidora $cupo,
        private RegistrarAuditoriaAction $auditoria,
    ) {}

    /**
     * Cuántas líneas se cobran aparte.
     *
     * Se cuenta, no se suman banderas: si la distribuidora desactiva una línea
     * incluida, la que quedaba "de más" pasa a ocupar su lugar y deja de ser
     * extra. Solo cuentan las líneas que siguen activas en el catálogo (K3).
     */
    public function extrasQueSeCobran(): int
    {
        return max(0, $this->cupo->activas() - $this->cupo->incluidasEnElPlan());
    }

    /** Lo que se le va a cobrar en el siguiente periodo. */
    public function montoDelSiguienteCobro(): float
    {
        $suscripcion = $this->suscripcionActiva();

        $extras = $this->extrasQueSeCobran() * (float) $suscripcion->precio_linea_extra_contratado;

        return round((float) $suscripcion->precio_base_contratado + $extras, 2);
    }

    public function precioDeUnaLineaExtra(): float
    {
        return round((float) $this->suscripcionActiva()->precio_linea_extra_contratado, 2);
    }

    /**
     * La distribuidora acepta pagar una línea de más: se le agrega a su
     * suscripción para que quepa.
     */
    public function contratarUnaLineaExtra(): Suscripcion
    {
        $suscripcion = $this->suscripcionActiva();

        $suscripcion->increment('lineas_extra_contratadas');

        return $suscripcion->fresh();
    }

    /**
     * Al renovar: las extras se vuelven a contar con lo que de verdad tiene
     * activo hoy.
     *
     * Así una línea extra que se desactivó durante el periodo deja de cobrarse
     * en el siguiente, que es lo que pide E2-06. Lo llama la renovación de la
     * suscripción (G11).
     */
    public function recalcularExtrasParaRenovar(): Suscripcion
    {
        $suscripcion = $this->suscripcionActiva();
        $antes = (int) $suscripcion->lineas_extra_contratadas;
        $ahora = $this->extrasQueSeCobran();

        if ($antes === $ahora) {
            return $suscripcion;
        }

        $suscripcion->update(['lineas_extra_contratadas' => $ahora]);

        $this->auditoria->ejecutar(
            'suscripcion.extras_recalculadas',
            'suscripcion',
            $suscripcion->id,
            ['lineas_extra_contratadas' => $antes],
            [
                'lineas_extra_contratadas' => $ahora,
                'monto_siguiente_cobro' => $this->montoDelSiguienteCobro(),
            ]
        );

        return $suscripcion->fresh();
    }

    public function suscripcionActiva(): Suscripcion
    {
        $suscripcion = Suscripcion::where('estado', 'activa')->latest('id')->first();

        if (! $suscripcion) {
            throw new OperacionInvalidaException(
                'Tu distribuidora no tiene una suscripción activa.',
                409
            );
        }

        return $suscripcion;
    }
}
