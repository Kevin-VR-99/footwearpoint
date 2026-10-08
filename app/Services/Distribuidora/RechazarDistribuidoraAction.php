<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-195 (G4) — Rechazar la solicitud de una distribuidora, con su motivo.
 *
 * Es la única lógica de rechazo: la usan el panel del admin general y
 * POST /api/admin/distribuidoras/{id}/rechazar.
 *
 * El rechazo es definitivo (AprobarDistribuidoraAction solo aprueba
 * pendientes). No se toca a su administrador ni a sus roles: lo que le impide
 * operar es el estado, que revisa AccesoPanelWebService al entrar al panel.
 *
 * TG-196 (G5): al terminar avisa por correo a la distribuidora, con el motivo.
 */
class RechazarDistribuidoraAction
{
    public function __construct(
        private NotificarCambioEstadoDistribuidoraAction $notificar,
    ) {
    }

    public const LARGO_MAXIMO_MOTIVO = 300;

    public const MENSAJE_MOTIVO_OBLIGATORIO = 'Escribe el motivo del rechazo.';

    public const MENSAJE_MOTIVO_LARGO = 'El motivo no puede pasar de 300 caracteres.';

    public const MENSAJE_NO_PENDIENTE = 'Solo se pueden rechazar distribuidoras pendientes.';

    /**
     * @throws ValidationException si el motivo viene vacío o es muy largo.
     * @throws OperacionInvalidaException (422) si la distribuidora no está pendiente.
     */
    public function ejecutar(Distribuidora $distribuidora, ?string $motivo): CambioEstadoDistribuidora
    {
        $motivo = trim((string) $motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo_rechazo' => [self::MENSAJE_MOTIVO_OBLIGATORIO]]);
        }

        if (mb_strlen($motivo) > self::LARGO_MAXIMO_MOTIVO) {
            throw ValidationException::withMessages(['motivo_rechazo' => [self::MENSAJE_MOTIVO_LARGO]]);
        }

        $rechazada = DB::transaction(function () use ($distribuidora, $motivo) {
            // Se vuelve a leer con candado: si dos admins actúan a la vez, el
            // segundo ya ve el estado que dejó el primero.
            $actual = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);

            if ($actual->estado !== 'pendiente') {
                throw new OperacionInvalidaException(self::MENSAJE_NO_PENDIENTE, 422);
            }

            $actual->update([
                'estado'              => 'rechazada',
                'motivo_rechazo'      => $motivo,
                // Una pendiente no debería estar visible, pero por si acaso.
                'marketplace_visible' => false,
            ]);

            return $actual;
        });

        // Ya guardado: si el correo falla, el rechazo se queda.
        return new CambioEstadoDistribuidora(
            $rechazada,
            $this->notificar->ejecutar($rechazada, NotificarCambioEstadoDistribuidoraAction::RECHAZADA),
        );
    }
}
