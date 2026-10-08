<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * TG-194 (G2) — Aprobar la solicitud de una distribuidora.
 *
 * Es la única lógica de aprobación: la usan el panel del admin general y
 * POST /api/admin/distribuidoras/{id}/aprobar. Antes cada uno tenía su copia
 * y ninguno creaba los roles de la distribuidora.
 *
 * TG-196 (G5): al terminar avisa por correo a la distribuidora.
 */
class AprobarDistribuidoraAction
{
    public function __construct(
        private PrepararDistribuidoraAction $preparar,
        private ProvisionarRolesDistribuidoraAction $roles,
        private NotificarCambioEstadoDistribuidoraAction $notificar,
    ) {
    }

    /**
     * @throws AprobacionDistribuidoraException si no está pendiente o no hay planes.
     */
    public function ejecutar(Distribuidora $distribuidora): CambioEstadoDistribuidora
    {
        if ($distribuidora->estado !== 'pendiente') {
            throw AprobacionDistribuidoraException::noPendiente();
        }

        $plan = $this->preparar->planPorDefecto();

        if (! $plan) {
            throw AprobacionDistribuidoraException::sinPlan();
        }

        DB::transaction(function () use ($distribuidora, $plan) {
            $distribuidora->update([
                'estado'           => 'activa',
                'fecha_aprobacion' => now(),
            ]);

            $this->preparar->datosIniciales($distribuidora);

            // Igual que antes de TG-194: aprobar siempre abre una suscripción
            // nueva con el plan por defecto.
            $this->preparar->suscripcionInicial($distribuidora, $plan);

            $this->roles->ejecutar($distribuidora);

            // Una solicitud de antes de TG-194 pudo quedar con su administrador
            // registrado pero sin rol (no había roles que asignarle).
            $administradores = Tenant::forzar($distribuidora->id, fn () => DistribuidoraStaff::with('usuario')
                ->where('distribuidora_id', $distribuidora->id)
                ->where('tipo', 'administrador')
                ->where('estado', 'activo')
                ->get());

            foreach ($administradores as $staff) {
                $this->roles->asignarAdministrador($staff->usuario, $distribuidora);
            }
        });

        $distribuidora = $distribuidora->fresh();

        // Ya guardado: si el correo falla, la aprobación se queda.
        return new CambioEstadoDistribuidora(
            $distribuidora,
            $this->notificar->ejecutar($distribuidora, NotificarCambioEstadoDistribuidoraAction::APROBADA),
        );
    }
}
