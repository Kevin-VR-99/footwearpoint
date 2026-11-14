<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Services\Auditoria\AuditarCambios;
use App\Support\Tenant;

/**
 * Descuento de mayoreo de una línea activa de esta distribuidora.
 *
 * Si el porcentaje queda vacío, la línea no tiene descuento.
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

        // TG-334: solo si cambió.
        $fila->descuento_mayorista_pct = $porcentaje;
        app(AuditarCambios::class)->guardar($fila, 'oferta.descuento_linea', 'distribuidora_linea');

        return $fila->fresh();
    }
}
