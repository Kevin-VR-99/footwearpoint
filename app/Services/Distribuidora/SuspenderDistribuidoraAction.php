<?php

namespace App\Services\Distribuidora;

use App\Services\Auditoria\RegistrarAuditoriaAction;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use Illuminate\Support\Facades\DB;

/**
 * TG-196 (G5) — Suspender una distribuidora activa.
 *
 * Es la única lógica de suspensión: la usan el panel del admin general y
 * POST /api/admin/distribuidoras/{id}/suspender.
 *
 * Solo cambia el estado; no se borra ni se modifica nada más (suscripción,
 * personal, revendedores, clientes, inventario, visibilidad en el
 * marketplace). Con eso basta para que no pueda operar: el login, la API y
 * el Tenant revisan Distribuidora::ESTADOS_SIN_OPERACION. Al reactivarla
 * todo vuelve como estaba.
 */
class SuspenderDistribuidoraAction
{
    public const MENSAJE_NO_ACTIVA = 'Solo se pueden suspender distribuidoras activas.';

    public function __construct(
        private NotificarCambioEstadoDistribuidoraAction $notificar,
    ) {
    }

    /**
     * @throws OperacionInvalidaException (422) si la distribuidora no está activa.
     */
    public function ejecutar(Distribuidora $distribuidora): CambioEstadoDistribuidora
    {
        $suspendida = DB::transaction(function () use ($distribuidora) {
            // Con candado, igual que el rechazo: si dos admins actúan a la vez,
            // el segundo ya ve el estado que dejó el primero.
            $actual = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);

            if ($actual->estado !== 'activa') {
                throw new OperacionInvalidaException(self::MENSAJE_NO_ACTIVA, 422);
            }

            $previos = ['estado' => $actual->estado, 'motivo_suspension' => $actual->motivo_suspension];

            $actual->update([
                'estado' => 'suspendida',
                'motivo_suspension' => Distribuidora::MOTIVO_MANUAL,
            ]);

            // TG-334: sin distribuidora; lo ve el admin general.
            app(RegistrarAuditoriaAction::class)->ejecutar('distribuidora.suspendida', 'distribuidora', (int) $actual->id,
                $previos, ['estado' => 'suspendida', 'motivo_suspension' => Distribuidora::MOTIVO_MANUAL]);

            return $actual;
        });

        // Ya guardado: si el correo falla, la suspensión se queda.
        return new CambioEstadoDistribuidora(
            $suspendida,
            $this->notificar->ejecutar($suspendida, NotificarCambioEstadoDistribuidoraAction::SUSPENDIDA),
        );
    }
}
