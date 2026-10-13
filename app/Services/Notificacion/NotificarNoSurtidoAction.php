<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-241 (A12 / E8-05) — Avisarle a la persona que algo de su pedido no llegó.
 *
 * El criterio lo pide expresamente: "el cliente recibe una notificación del
 * cambio y puede elegir". El aviso dice QUÉ no llegó, POR QUÉ, y que tiene
 * que elegir, porque una notificación que solo dice "hubo un cambio" obliga a
 * llamar por teléfono.
 *
 * Solo se avisa a quien tiene cuenta en la app; a los demás los llama la
 * distribuidora. Y si Firebase falla, el aviso de la bandeja ya quedó.
 */
class NotificarNoSurtidoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(PedidoDetalle $detalle, Pedido $pedido): void
    {
        $usuarioId = $this->cuentaDe($pedido);

        if ($usuarioId === null) {
            return;
        }

        $titulo = 'No llegó un producto de tu pedido '.$pedido->folio;

        $mensaje = sprintf(
            'La fábrica no surtió «%s» de tu pedido %s%s. Dinos qué prefieres: que te lo cambiemos por otro producto, o que te devolvamos tu anticipo.',
            $detalle->producto_nombre,
            $pedido->folio,
            $detalle->motivo_no_surtido ? ' ('.$detalle->motivo_no_surtido.')' : '',
        );

        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => (int) $pedido->distribuidora_id,
            'tipo'             => 'pedido_no_surtido',
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => $pedido->id,
        ]);

        $this->mandarPush($usuarioId, $titulo, $mensaje, $pedido);
    }

    /** El pedido es de un cliente directo o de un cliente mayorista. */
    private function cuentaDe(Pedido $pedido): ?int
    {
        $pedido->loadMissing(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        $usuarioId = $pedido->clienteDirecto?->usuario_id
            ?? $pedido->revendedorAfiliacion?->revendedor?->usuario_id;

        return $usuarioId !== null ? (int) $usuarioId : null;
    }

    private function mandarPush(int $usuarioId, string $titulo, string $mensaje, Pedido $pedido): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        try {
            $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                'tipo'         => 'pedido_no_surtido',
                'entidad_tipo' => 'pedido',
                'entidad_id'   => (string) $pedido->id,
            ]);

            if ($invalidos !== []) {
                DispositivoFcm::whereIn('token', $invalidos)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el push de producto no surtido.', [
                'pedido_id'  => $pedido->id,
                'usuario_id' => $usuarioId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
