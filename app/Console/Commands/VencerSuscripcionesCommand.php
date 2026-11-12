<?php

namespace App\Console\Commands;

use App\Services\Suscripcion\VencerSuscripciones;
use Illuminate\Console\Command;

/**
 * Revisa el vencimiento de las mensualidades.
 *
 * Sin opciones solo avisa y marca la suscripción como vencida: no corta el
 * acceso. Con --suspender, al sexto día sin pago suspende por falta de pago.
 * Lo mismo hace SUSCRIPCION_VENCIMIENTO=suspender.
 *
 * No está programado en la aplicación. El cron, cuando se cree, va en la
 * documentación de vencimiento.
 */
class VencerSuscripcionesCommand extends Command
{
    protected $signature = 'suscripciones:vencer
        {--suspender : Al sexto día sin pago suspende por falta de pago}
        {--dry-run : Solo cuenta qué revisaría, sin cambiar nada ni mandar avisos}';

    protected $description = 'Marca suscripciones vencidas, avisa y, si se pide, suspende por falta de pago';

    public function handle(VencerSuscripciones $accion): int
    {
        $suspender = (bool) $this->option('suspender') || config('suscripcion.vencimiento') === 'suspender';
        $simular = (bool) $this->option('dry-run');
        $resultado = $accion->ejecutar($suspender, $simular);

        $modo = $suspender ? 'suspender' : 'solo avisos';
        $prefijo = $simular ? 'Simulación' : 'Listo';

        $this->info(sprintf(
            '%s (%s). Revisadas: %d. Pasadas a vencida: %d. Suspendidas por falta de pago: %d. Avisos nuevos: %d.',
            $prefijo,
            $modo,
            $resultado['revisadas'],
            $resultado['vencidas'],
            $resultado['suspendidas'],
            $resultado['avisos'],
        ));

        return self::SUCCESS;
    }
}
