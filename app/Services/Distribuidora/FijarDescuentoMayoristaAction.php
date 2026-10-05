<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ConfiguracionDistribuidora;
use App\Support\Tenant;

/**
 * El descuento general de mayoreo de la distribuidora (TG-212, D8).
 *
 * Es el porcentaje que se le baja al precio de catálogo para sus clientes
 * mayoristas, cuando el producto no tiene un precio propio.
 */
class FijarDescuentoMayoristaAction
{
    public function ejecutar(float $porcentaje): ConfiguracionDistribuidora
    {
        if ($porcentaje < 0 || $porcentaje >= 100) {
            throw new OperacionInvalidaException(
                'El descuento de mayoreo tiene que ser un porcentaje de 0 a 99.99.',
                422
            );
        }

        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora.');

        $configuracion = ConfiguracionDistribuidora::query()->first();

        if (! $configuracion) {
            throw new OperacionInvalidaException(
                'Tu distribuidora todavía no tiene configuración; no se puede fijar el descuento.',
                409
            );
        }

        $configuracion->update(['descuento_mayorista_pct' => round($porcentaje, 2)]);

        return $configuracion->fresh();
    }
}
