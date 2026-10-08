<?php

namespace App\Services\Notificacion;

use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Support\Tenant;

/**
 * TG-230 (G11) — Avisa en la bandeja del panel a los administradores de la
 * distribuidora que Mercado Pago confirmó el pago de su mensualidad.
 *
 * Se envuelve en Tenant::forzar porque el aviso de Mercado Pago (G9) llega
 * sin sesión.
 */
class NotificarPagoSuscripcionAction
{
    public function ejecutar(Pago $pago, Suscripcion $suscripcion, bool $renovada): void
    {
        Tenant::forzar((int) $pago->distribuidora_id, function () use ($pago, $suscripcion, $renovada) {
            $monto = '$'.number_format((float) $pago->monto, 2);
            $titulo = 'Recibimos el pago de tu mensualidad';
            $mensaje = $renovada
                ? "Mercado Pago confirmó tu pago de {$monto} (folio {$pago->folio}). Tu suscripción queda pagada hasta el {$suscripcion->fecha_fin?->format('d/m/Y')}."
                : "Mercado Pago confirmó tu pago de {$monto} (folio {$pago->folio}). El equipo de FootwearPoint revisará tu suscripción.";

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
        });
    }
}
