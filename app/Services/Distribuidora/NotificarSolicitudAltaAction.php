<?php

namespace App\Services\Distribuidora;

use App\Mail\SolicitudAltaDistribuidoraMail;
use App\Mail\SolicitudAltaRecibidaMail;
use App\Models\Distribuidora;
use App\Models\Usuario;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * TG-220 (E2-08) — Avisa a los admin general de que llegó una solicitud de
 * alta por el formulario público.
 *
 * Mismo criterio que NotificarCambioEstadoDistribuidoraAction: se manda en el
 * momento, un correo por persona para que nadie vea el correo de los demás, y
 * un correo que falla NUNCA deshace la solicitud. Se reporta al log y se
 * devuelve false.
 *
 * Además le manda al administrador de la distribuidora el acuse de que su
 * solicitud llegó (SolicitudAltaRecibidaMail), con el mismo criterio: si falla,
 * se reporta y la solicitud se queda.
 */
class NotificarSolicitudAltaAction
{
    /** @return bool true si el aviso salió a todos sus destinatarios. */
    public function ejecutar(Distribuidora $distribuidora, Usuario $administrador): bool
    {
        $this->avisarAlSolicitante($distribuidora, $administrador);

        $destinatarios = $this->adminsGenerales();

        if ($destinatarios->isEmpty()) {
            return false;
        }

        $enviado = true;

        foreach ($destinatarios as $admin) {
            try {
                Mail::to($admin->email)->send(new SolicitudAltaDistribuidoraMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    nombreAdministrador: (string) $administrador->nombre,
                    emailAdministrador: (string) $administrador->email,
                    planSolicitado: $distribuidora->planSolicitado?->nombre,
                    nombreDestinatario: $admin->nombre,
                ));
            } catch (Throwable $e) {
                report($e);
                $enviado = false;
            }
        }

        return $enviado;
    }

    /** El acuse para quien pidió el alta. Nunca lanza: un fallo va al log. */
    private function avisarAlSolicitante(Distribuidora $distribuidora, Usuario $administrador): bool
    {
        if (blank($administrador->email)) {
            return false;
        }

        try {
            Mail::to($administrador->email)->send(new SolicitudAltaRecibidaMail(
                nombreDistribuidora: $distribuidora->nombre_comercial,
                nombreDestinatario: $administrador->nombre,
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Los admin general activos. Su rol vive en el equipo 0, no en ninguna
     * distribuidora.
     *
     * @return \Illuminate\Support\Collection<int, Usuario>
     */
    private function adminsGenerales()
    {
        $registrar = app(PermissionRegistrar::class);
        $teamAnterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId(0);

            return Usuario::query()
                ->where('estado', 'activo')
                ->whereNotNull('email')
                ->role('admin_general')
                ->get();
        } finally {
            $registrar->setPermissionsTeamId($teamAnterior);
            $registrar->forgetCachedPermissions();
        }
    }
}
