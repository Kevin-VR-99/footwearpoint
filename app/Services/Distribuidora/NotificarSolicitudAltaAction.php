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
 * TG-220 (E2-08) — Avisa de que llegó una solicitud de alta por el formulario
 * público. El aviso va al buzón de FootwearPoint
 * (config footwearpoint.solicitudes_alta.correo), no a la cuenta con la que
 * se entra al panel.
 *
 * Mismo criterio que NotificarCambioEstadoDistribuidoraAction: se manda en el
 * momento y un correo que falla NUNCA deshace la solicitud. Se reporta al log
 * y se devuelve false.
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

        $destinatarios = $this->correosDeAviso();

        if ($destinatarios === []) {
            return false;
        }

        $enviado = true;

        foreach ($destinatarios as $correo) {
            try {
                Mail::to($correo)->send(new SolicitudAltaDistribuidoraMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    nombreAdministrador: (string) $administrador->nombre,
                    emailAdministrador: (string) $administrador->email,
                    planSolicitado: $distribuidora->planSolicitado?->nombre,
                ));
            } catch (Throwable $e) {
                report($e);
                $enviado = false;
            }
        }

        return $enviado;
    }

    /**
     * El buzón configurado. Si no hay uno válido, cae en los admin general
     * activos para no perder el aviso.
     *
     * @return list<string>
     */
    private function correosDeAviso(): array
    {
        $configurado = config('footwearpoint.solicitudes_alta.correo');
        $correo = is_string($configurado) ? trim($configurado) : '';

        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return [$correo];
        }

        return $this->adminsGenerales()
            ->pluck('email')
            ->map(fn ($email) => is_string($email) ? trim($email) : '')
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
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
