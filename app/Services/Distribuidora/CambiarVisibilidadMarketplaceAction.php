<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;

/**
 * TG-197 (G16) — Decidir si una distribuidora aparece o no en el marketplace
 * (E2-05, primer criterio).
 *
 * Ya existía en el panel del admin general y en
 * PATCH /api/admin/marketplace/config, cada uno con su copia; ahora los dos
 * usan esta acción. Mismo comportamiento: se puede ocultar cualquiera, pero
 * solo una activa puede hacerse visible. Aprobar no la hace visible sola.
 */
class CambiarVisibilidadMarketplaceAction
{
    public const MENSAJE_SOLO_ACTIVAS = 'Solo distribuidoras activas pueden ser visibles en el marketplace.';

    /**
     * @throws OperacionInvalidaException (422) si se quiere mostrar una que no está activa.
     */
    public function ejecutar(Distribuidora $distribuidora, bool $visible): Distribuidora
    {
        if ($visible && $distribuidora->estado !== 'activa') {
            throw new OperacionInvalidaException(self::MENSAJE_SOLO_ACTIVAS, 422);
        }

        $distribuidora->update(['marketplace_visible' => $visible]);

        return $distribuidora;
    }
}
