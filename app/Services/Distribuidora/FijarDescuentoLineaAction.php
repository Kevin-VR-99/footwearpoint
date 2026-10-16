<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Support\Tenant;

/**
 * Descuento de mayoreo de una línea activa de esta distribuidora.
 *
 * El general sigue existiendo: se usa solo cuando esta línea no tiene el suyo.
 */
class FijarDescuentoLineaAction
{
    public function ejecutar(int $lineaId, ?float $porcentaje): DistribuidoraLinea
    {
        if ($porcentaje !== null) {
            $porcentaje = round($porcentaje, 2);
        }

        if ($porcentaje !== null && ($porcentaje < 0 || $porcentaje >= 100)) {
            throw new OperacionInvalidaException(
                'El descuento de mayoreo tiene que ser un porcentaje de 0 a 99.99.',
                422
            );
        }

        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $fila = DistribuidoraLinea::query()
            ->vigentes()
            ->where('linea_id', $lineaId)
            ->first();

        if ($fila === null) {
            throw new OperacionInvalidaException(
                'Esa línea no está activa en tu distribuidora.',
                422
            );
        }

        $fila->update(['descuento_mayorista_pct' => $porcentaje]);

        return $fila->fresh();
    }
}
