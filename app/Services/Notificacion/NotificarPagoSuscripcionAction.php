<?php

namespace App\Services\Notificacion;

use App\Mail\PagoSuscripcionMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Support\Tenant;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa a la distribuidora que Mercado Pago confirmó un cobro de suscripción.
 *
 * La bandeja del panel solo llega a los administradores. El correo, además,
 * sale al correo público si es distinto. Un correo que falla no deshace el
 * pago: se reporta y se sigue con los demás destinatarios.
 *
 * Se envuelve en Tenant::forzar porque el aviso de Mercado Pago llega sin sesión.
 */
class NotificarPagoSuscripcionAction
{
    public function ejecutar(Pago $pago, Suscripcion $suscripcion, bool $renovada): void
    {
        Tenant::forzar((int) $pago->distribuidora_id, function () use ($pago, $suscripcion, $renovada) {
            $lineaExtra = $pago->referencia === Pago::REFERENCIA_LINEA_EXTRA;
            $monto = '$'.number_format((float) $pago->monto, 2);
            $hasta = $suscripcion->fecha_fin?->format('d/m/Y');

            if ($lineaExtra) {
                $titulo = 'Recibimos el pago de una línea extra';
                $mensaje = "Mercado Pago confirmó tu pago de {$monto} (folio {$pago->folio}). "
                    .'Se agregó una línea extra a tu suscripción. Tu periodo pagado'
                    .($hasta !== null ? " sigue hasta el {$hasta}" : ' no cambia')
                    .'. En la siguiente mensualidad se cobra si esa línea sigue activa por encima de tu plan.';
            } elseif ($renovada) {
                $titulo = 'Recibimos el pago de tu mensualidad';
                $mensaje = "Mercado Pago confirmó tu pago de {$monto} (folio {$pago->folio}). Tu suscripción queda pagada hasta el {$hasta}.";
            } else {
                $titulo = 'Recibimos el pago de tu mensualidad';
                $mensaje = "Mercado Pago confirmó tu pago de {$monto} (folio {$pago->folio}). El equipo de FootwearPoint revisará tu suscripción.";
            }

            DistribuidoraStaff::query()
                ->where('distribuidora_id', $pago->distribuidora_id)
                ->where('tipo', 'administrador')
                ->where('estado', 'activo')
                ->whereNotNull('usuario_id')
                ->pluck('usuario_id')
                ->each(fn ($usuarioId) => Notificacion::create([
                    'usuario_id'       => (int) $usuarioId,
                    'distribuidora_id' => $pago->distribuidora_id,
                    'tipo'             => 'pago_suscripcion',
                    'titulo'           => $titulo,
                    'mensaje'          => $mensaje,
                    'leida_at'         => null,
                    'entidad_tipo'     => 'suscripcion',
                    'entidad_id'       => $suscripcion->id,
                ]));

            if ($lineaExtra || $renovada) {
                $this->enviarCorreo($pago, $suscripcion, $lineaExtra);
            }
        });
    }

    private function enviarCorreo(Pago $pago, Suscripcion $suscripcion, bool $lineaExtra): void
    {
        $distribuidora = Distribuidora::query()->find($pago->distribuidora_id);

        if ($distribuidora === null) {
            return;
        }

        $monto = number_format((float) $pago->monto, 2);
        $tipo = $lineaExtra ? PagoSuscripcionMail::LINEA_EXTRA : PagoSuscripcionMail::MENSUALIDAD;

        foreach ($this->destinatarios($distribuidora) as $correo => $nombre) {
            try {
                Mail::to($correo)->send(new PagoSuscripcionMail(
                    nombreDistribuidora: $distribuidora->nombre_comercial,
                    tipo: $tipo,
                    monto: $monto,
                    folio: (string) $pago->folio,
                    pagadoHasta: $suscripcion->fecha_fin?->format('d/m/Y'),
                    plan: $suscripcion->plan?->nombre,
                    lineasExtra: (int) $suscripcion->lineas_extra_contratadas,
                    nombreDestinatario: $nombre,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array<string, ?string> correo => nombre */
    private function destinatarios(Distribuidora $distribuidora): array
    {
        $administradores = DistribuidoraStaff::with('usuario')
            ->where('distribuidora_id', $distribuidora->id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->orderBy('id')
            ->get();

        $destinatarios = [];

        foreach ($administradores as $staff) {
            $correo = strtolower(trim((string) $staff->usuario?->email));

            if ($correo !== '') {
                $destinatarios[$correo] = $staff->usuario->nombreVisible();
            }
        }

        $correoPublico = strtolower(trim((string) $distribuidora->email_publico));

        if ($correoPublico !== '' && ! array_key_exists($correoPublico, $destinatarios)) {
            $destinatarios[$correoPublico] = null;
        }

        return $destinatarios;
    }
}
