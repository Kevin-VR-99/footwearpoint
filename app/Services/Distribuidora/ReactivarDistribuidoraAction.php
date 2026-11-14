<?php

namespace App\Services\Distribuidora;

use App\Services\Auditoria\RegistrarAuditoriaAction;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use Illuminate\Support\Facades\DB;

/**
 * TG-196 (G5) — Reactivar una distribuidora suspendida.
 *
 * Es la única lógica de reactivación: la usan el panel del admin general y
 * POST /api/admin/distribuidoras/{id}/reactivar. Solo regresa el estado a
 * activa; como la suspensión no tocó nada más, todo vuelve como estaba.
 */
class ReactivarDistribuidoraAction
{
    public const MENSAJE_NO_SUSPENDIDA = 'Solo se pueden reactivar distribuidoras suspendidas.';

    public function __construct(
        private NotificarCambioEstadoDistribuidoraAction $notificar,
    ) {
    }

    /**
     * @throws OperacionInvalidaException (422) si la distribuidora no está suspendida.
     */
    public function ejecutar(Distribuidora $distribuidora): CambioEstadoDistribuidora
    {
        $reactivada = DB::transaction(function () use ($distribuidora) {
            $actual = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);

            if ($actual->estado !== 'suspendida') {
                throw new OperacionInvalidaException(self::MENSAJE_NO_SUSPENDIDA, 422);
            }

            $previos = ['estado' => $actual->estado, 'motivo_suspension' => $actual->motivo_suspension];

            $actual->update([
                'estado' => 'activa',
                'motivo_suspension' => null,
            ]);

            // TG-334: sin distribuidora; lo ve el admin general.
            app(RegistrarAuditoriaAction::class)->ejecutar('distribuidora.reactivada', 'distribuidora', (int) $actual->id,
                $previos, ['estado' => 'activa', 'motivo_suspension' => null]);

            return $actual;
        });

        // Ya guardado: si el correo falla, la reactivación se queda.
        return new CambioEstadoDistribuidora(
            $reactivada,
            $this->notificar->ejecutar($reactivada, NotificarCambioEstadoDistribuidoraAction::REACTIVADA),
        );
    }
}
