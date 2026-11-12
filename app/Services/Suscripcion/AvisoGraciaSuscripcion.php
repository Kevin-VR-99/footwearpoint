<?php

namespace App\Services\Suscripcion;

use App\Models\Distribuidora;
use App\Support\Tenant;

/**
 * Aviso fijo del panel el día del vencimiento y durante los 5 días de gracia.
 * No se muestra si la distribuidora ya no está activa.
 */
class AvisoGraciaSuscripcion
{
    /** @return array{texto: string, url: string}|null */
    public function actual(): ?array
    {
        $id = Tenant::id();

        if ($id === null) {
            return null;
        }

        $distribuidora = Distribuidora::query()->find($id);

        if ($distribuidora === null || $distribuidora->estado !== 'activa') {
            return null;
        }

        $suscripcion = CalendarioVencimiento::suscripcionDe($id);

        if ($suscripcion === null) {
            return null;
        }

        $dias = CalendarioVencimiento::diasDesde($suscripcion->fecha_fin);
        $url = route('distribuidora.configuracion', ['pestana' => 'suscripcion']);

        if ($dias === 0) {
            return [
                'texto' => 'Tu mensualidad vence hoy. Págala para no entrar en el periodo de gracia.',
                'url' => $url,
            ];
        }

        if (! CalendarioVencimiento::enGracia($dias)) {
            return null;
        }

        $quedan = CalendarioVencimiento::diasAntesDeSuspender($dias);
        $plazo = $quedan === 1 ? 'Te queda 1 día de gracia.' : "Te quedan {$quedan} días de gracia.";

        return [
            'texto' => 'Tu mensualidad está vencida. '.$plazo.' Si pagas ahora, tu fecha de vencimiento se conserva.',
            'url' => $url,
        ];
    }
}
