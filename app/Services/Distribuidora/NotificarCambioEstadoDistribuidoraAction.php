<?php

namespace App\Services\Distribuidora;

use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
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
        $planes = $evento === self::APROBADA ? $this->planes($distribuidora) : [];

        // Un correo por persona: así nadie ve el correo de los demás.
        foreach ($destinatarios as $correo => $nombre) {
            try {
                Mail::to($correo)->send(new CambioEstadoDistribuidoraMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    evento: $evento,
                    nombreDestinatario: $nombre,
                    motivo: $evento === self::RECHAZADA ? $distribuidora->motivo_rechazo : null,
                    planes: $planes,
                ));
            } catch (Throwable $e) {
                report($e);
                $enviado = false;
            }
        }

        return $enviado;
    }

    /**
     * TG-220 — Los planes activos con su precio, del más barato al más caro,
     * con el suyo marcado (el de su suscripción por pagar o el que pidió). Si
     * el suyo ya no está activo, se agrega igual para que sepa cuánto paga.
     */
    private function planes(Distribuidora $distribuidora): array
    {
        $suyo = Suscripcion::pendientePrimerPago((int) $distribuidora->id)?->plan_id
            ?? $distribuidora->plan_solicitado_id;

        $planes = PlanSuscripcion::query()
            ->where(fn ($q) => $q->where('activo', true)->when($suyo, fn ($q) => $q->orWhere('id', $suyo)))
            ->orderBy('precio_base_mensual')
            ->orderBy('id')
            ->get();

        return $planes->map(fn (PlanSuscripcion $plan) => [
            'nombre'              => (string) $plan->nombre,
            'precio_base_mensual' => (float) $plan->precio_base_mensual,
            'lineas_incluidas'    => (int) $plan->lineas_incluidas,
            'precio_linea_extra'  => (float) $plan->precio_linea_extra,
            'destacado'           => $suyo !== null && (int) $plan->id === (int) $suyo,
        ])->values()->all();
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
