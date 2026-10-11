<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le avisa al cliente que su pedido no entró al pedido a fábrica porque le
 * falta el anticipo (TG-215).
 *
 * Solo se avisa si el cliente tiene cuenta en la app: tenerla es opcional, y a
 * quien no la tiene lo llama la distribuidora. El personal ya se entera por su
 * propia pantalla.
 *
 * Si Firebase falla no pasa nada: el pedido ya quedó pospuesto y el aviso de
 * la bandeja ya existe. Solo queda constancia en el log.
 */
class NotificarAnticipoPendienteAction
{
    public function __construct(private EnviadorPush $enviadorPush) {}

    public function ejecutar(Pedido $pedido, float $anticipoPendiente): void
    {
        $pedido->loadMissing('clienteDirecto');

        $usuarioId = $pedido->tipo === 'cliente_directo'
            ? $pedido->clienteDirecto?->usuario_id
            : null;

        if (! $usuarioId) {
            return;
        }

        $titulo = 'Tu pedido '.$pedido->folio.' no entró esta semana';
        $mensaje = sprintf(
            'Tu pedido %s no entró al pedido a fábrica de esta semana porque falta tu anticipo ($%s). '
            .'Cuando lo pagues, entrará en el siguiente.',
            $pedido->folio,
            number_format($anticipoPendiente, 2)
        );

        Notificacion::create([
            'usuario_id' => (int) $usuarioId,
            'distribuidora_id' => (int) $pedido->distribuidora_id,
            'tipo' => 'pedido_anticipo_pendiente',
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'leida_at' => null,
            'entidad_tipo' => 'pedido',
            'entidad_id' => $pedido->id,
        ]);

        $this->mandarPush((int) $usuarioId, $titulo, $mensaje, $pedido);
    }

    private function mandarPush(int $usuarioId, string $titulo, string $mensaje, Pedido $pedido): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        try {
            $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                // Para que la app abra el pedido al tocar el aviso.
                'tipo' => 'pedido_anticipo_pendiente',
                'entidad_tipo' => 'pedido',
                'entidad_id' => (string) $pedido->id,
            ]);

            // Celulares donde ya no existe la app.
            if ($invalidos !== []) {
                DispositivoFcm::whereIn('token', $invalidos)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el push de anticipo pendiente.', [
                'pedido_id' => $pedido->id,
                'usuario_id' => $usuarioId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
