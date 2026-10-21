<?php

namespace App\Services\Notificacion;

use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\SolicitudCambio;

/**
 * TG-281 — Avisarle al personal que alguien pidió un cambio.
 *
 * Faltaba: la persona tocaba «Solicitar cambio» en la app, la app le decía
 * que quedó registrado, y la solicitud se quedaba esperando a que a alguien
 * se le ocurriera entrar a mirar la pantalla correcta. Podía pasar días así,
 * y el plazo para gestionarla con la fábrica corre igual.
 *
 * Va solo al personal, en su bandeja del panel. Al cliente no se le avisa:
 * él acaba de pedirlo y ya vio la confirmación en pantalla; un aviso de algo
 * que uno mismo hizo hace dos segundos es ruido.
 *
 * Sin push a propósito: el personal trabaja en el panel web, no en la app.
 */
class NotificarCambioSolicitadoAction
{
    public function ejecutar(SolicitudCambio $solicitud, string $producto, string $quienPide): void
    {
        $titulo = 'Pidieron un cambio: '.$producto;
        $mensaje = sprintf(
            '%s pidió cambiar «%s». Motivo: %s. Hay que gestionarlo antes del %s.',
            $quienPide,
            $producto,
            trim((string) $solicitud->motivo),
            $solicitud->fecha_limite_gestion_fabrica?->format('d/m/Y') ?? 'la fecha límite',
        );

        // TG-224: sin scope a propósito; filtra por la distribuidora de la
        // solicitud y puede correr sin sesión de personal (lo pide el cliente
        // desde la app).
        $staffs = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $solicitud->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id');

        foreach ($staffs as $usuarioId) {
            Notificacion::create([
                'usuario_id'       => (int) $usuarioId,
                'distribuidora_id' => (int) $solicitud->distribuidora_id,
                'tipo'             => 'cambio_solicitado',
                'titulo'           => $titulo,
                'mensaje'          => $mensaje,
                'leida_at'         => null,
                'entidad_tipo'     => 'solicitud_cambio',
                'entidad_id'       => $solicitud->id,
            ]);
        }
    }
}
