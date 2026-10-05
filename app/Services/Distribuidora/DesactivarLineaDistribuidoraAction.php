<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Services\Auditoria\RegistrarAuditoriaAction;

/**
 * La distribuidora deja de vender una línea (TG-210, TG-211).
 *
 * No se borra la fila: queda el historial de que la vendió y desde cuándo. Al
 * desactivarla, su lugar del plan queda libre para otra línea.
 *
 * Si era una línea de las que se cobran aparte, queda anotado en la auditoría:
 * así después se puede explicar por qué bajó el cobro. Lo que de verdad se
 * cobra se recalcula al renovar (ver CobroSuscripcion).
 */
class DesactivarLineaDistribuidoraAction
{
    public function __construct(
        private CobroSuscripcion $cobro,
        private RegistrarAuditoriaAction $auditoria,
    ) {}

    public function ejecutar(int $lineaId): DistribuidoraLinea
    {
        $suya = DistribuidoraLinea::with('linea')->where('linea_id', $lineaId)->first();

        if (! $suya) {
            throw new OperacionInvalidaException('Tu distribuidora no tiene activada esa línea.', 404);
        }

        if (! $suya->activa) {
            return $suya;
        }

        $extrasAntes = $this->cobro->extrasQueSeCobran();

        $suya->update(['activa' => false]);

        $extrasDespues = $this->cobro->extrasQueSeCobran();

        if ($extrasDespues < $extrasAntes) {
            $this->auditoria->ejecutar(
                'linea_extra.desactivada',
                'distribuidora_linea',
                $suya->id,
                ['lineas_extra_que_se_cobran' => $extrasAntes],
                [
                    'linea' => $suya->linea?->nombre,
                    'lineas_extra_que_se_cobran' => $extrasDespues,
                ]
            );
        }

        return $suya;
    }
}
