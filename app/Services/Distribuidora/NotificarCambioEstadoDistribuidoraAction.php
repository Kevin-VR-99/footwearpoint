<?php

namespace App\Services\Distribuidora;

use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Support\Tenant;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TG-196 (G5) — Avisa por correo a la distribuidora cuando cambia su estado
 * (E2-02: "El sistema notifica a la distribuidora del cambio de estado").
 *
 * Se manda a sus administradores activos; si no tiene ninguno con correo, a
 * su correo público. Se envía en el momento (como el comprobante de venta) y
 * un correo que falla nunca deshace el cambio: se reporta al log (TG-224) y
 * se devuelve false para que el admin general sepa que no se avisó.
 */
class NotificarCambioEstadoDistribuidoraAction
{
    public const APROBADA = 'aprobada';

    public const RECHAZADA = 'rechazada';

    public const SUSPENDIDA = 'suspendida';

    public const REACTIVADA = 'reactivada';

    /** @return bool true si el aviso salió a todos sus destinatarios. */
    public function ejecutar(Distribuidora $distribuidora, string $evento): bool
    {
        $destinatarios = $this->destinatarios($distribuidora);

        if ($destinatarios === []) {
            return false;
        }

        $enviado = true;

        // Un correo por persona: así nadie ve el correo de los demás.
        foreach ($destinatarios as $correo => $nombre) {
            try {
                Mail::to($correo)->send(new CambioEstadoDistribuidoraMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    evento: $evento,
                    nombreDestinatario: $nombre,
                    motivo: $evento === self::RECHAZADA ? $distribuidora->motivo_rechazo : null,
                ));
            } catch (Throwable $e) {
                report($e);
                $enviado = false;
            }
        }

        return $enviado;
    }

    /** @return array<string, ?string> correo => nombre */
    private function destinatarios(Distribuidora $distribuidora): array
    {
        // Mismo criterio que AprobarDistribuidoraAction: el staff se consulta
        // dentro del contexto de esa distribuidora.
        $administradores = Tenant::forzar($distribuidora->id, fn () => DistribuidoraStaff::with('usuario')
            ->where('distribuidora_id', $distribuidora->id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->orderBy('id')
            ->get());

        $destinatarios = [];

        foreach ($administradores as $staff) {
            $correo = trim((string) $staff->usuario?->email);

            if ($correo !== '') {
                $destinatarios[$correo] = $staff->usuario->nombre;
            }
        }

        $correoPublico = trim((string) $distribuidora->email_publico);

        if ($destinatarios === [] && $correoPublico !== '') {
            $destinatarios[$correoPublico] = null;
        }

        return $destinatarios;
    }
}
