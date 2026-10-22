<?php

namespace App\Services\Notificacion;

use App\Mail\EstadoSolicitudCambioMail;
use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\SolicitudCambio;
use App\Models\Usuario;
use App\Services\Correo\IntentarEnviarCorreoAction;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Le avisa al cliente cada paso de su solicitud de cambio: en su bandeja de
 * la app (siempre), por push (si tiene la app) y por correo (si tiene uno).
 * Igual que el resto de avisos: si push o correo fallan, la bandeja ya quedó.
 */
class NotificarEstadoCambioAction
{
    public function __construct(
        private EnviadorPush $enviadorPush,
        private IntentarEnviarCorreoAction $correo,
    ) {
    }

    public function ejecutar(SolicitudCambio $solicitud): void
    {
        $usuarioId = $solicitud->clienteDirecto?->usuario_id
            ?? $solicitud->revendedorAfiliacion?->revendedor?->usuario_id;

        if ($usuarioId === null) {
            return;
        }

        $titulo = 'Tu cambio: '.mb_strtolower(SolicitudCambio::etiqueta($solicitud->estado));
        $mensaje = $this->mensaje($solicitud);

        Notificacion::create([
            'usuario_id'       => (int) $usuarioId,
            'distribuidora_id' => (int) $solicitud->distribuidora_id,
            'tipo'             => 'cambio_estado',
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'solicitud_cambio',
            'entidad_id'       => $solicitud->id,
        ]);

        $this->push((int) $usuarioId, $titulo, $mensaje, $solicitud);

        $usuario = Usuario::find($usuarioId);
        if ($usuario && filled($usuario->email)) {
            $this->correo->ejecutar(
                fn () => Mail::to($usuario->email)->send(new EstadoSolicitudCambioMail($usuario->nombre ?? null, $titulo, $mensaje)),
                'aviso de estado de cambio',
                ['solicitud_id' => $solicitud->id],
            );
        }
    }

    private function mensaje(SolicitudCambio $solicitud): string
    {
        $linea = $solicitud->pedidoDetalle ?? $solicitud->ventaDetalle;
        $producto = trim(($linea?->producto_nombre ?? 'tu producto').' '.($linea?->talla ? 'talla '.$linea->talla : ''));

        $texto = match ($solicitud->estado) {
            'autorizada'       => "Autorizamos el cambio de {$producto}.",
            'rechazada'        => "No pudimos autorizar el cambio de {$producto}.",
            'enviada_fabrica'  => "Enviamos {$producto} a revisión con la fábrica.",
            'aceptada_fabrica' => "La fábrica aceptó el cambio de {$producto}.",
            'vale_emitido'     => "Te emitimos un vale por el cambio de {$producto}.",
            'cerrada'          => "Tu solicitud de cambio de {$producto} quedó cerrada.",
            default            => "Tu solicitud de cambio de {$producto} cambió de estado.",
        };

        if (filled($solicitud->resolucion) && in_array($solicitud->estado, ['rechazada', 'cerrada'], true)) {
            $texto .= ' '.$solicitud->resolucion;
        }

        return $texto;
    }

    private function push(int $usuarioId, string $titulo, string $mensaje, SolicitudCambio $solicitud): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();
        if ($tokens === []) {
            return;
        }

        try {
            $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                'tipo'         => 'cambio_estado',
                'entidad_tipo' => 'solicitud_cambio',
                'entidad_id'   => (string) $solicitud->id,
            ]);
            if ($invalidos !== []) {
                DispositivoFcm::whereIn('token', $invalidos)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el push del cambio.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);
        }
    }
}
