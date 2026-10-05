<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;

/**
 * La distribuidora deja de vender una línea (TG-210).
 *
 * No se borra la fila: queda el historial de que la vendió y desde cuándo. Al
 * desactivarla, su lugar del plan queda libre para otra línea.
 */
class DesactivarLineaDistribuidoraAction
{
    public function ejecutar(int $lineaId): DistribuidoraLinea
    {
        $suya = DistribuidoraLinea::where('linea_id', $lineaId)->first();

        if (! $suya) {
            throw new OperacionInvalidaException('Tu distribuidora no tiene activada esa línea.', 404);
        }

        if ($suya->activa) {
            $suya->update(['activa' => false]);
        }

        return $suya;
    }
}
