<?php

namespace App\Services;

use App\Models\HistorialEstadoPedido;
use App\Models\Pedido;

class CambiarEstadoPedidoService
{
    // Único lugar del proyecto que debe cambiar pedidos.estado. Lo usan
    // Paquete C (cerrar/solicitar/recibir un ciclo) y Paquete D (no surtido,
    // vencido sin recoger, y cualquier otro cambio de estado del pedido).
    // Nadie más debe escribir $pedido->estado = ... directamente.
    public function cambiar(Pedido $pedido, string $nuevoEstado, ?int $staffId = null, ?string $comentario = null): Pedido
    {
        $estadoAnterior = $pedido->estado;

        if ($estadoAnterior === $nuevoEstado) {
            return $pedido;
        }

        $pedido->estado = $nuevoEstado;
        $pedido->save();

        HistorialEstadoPedido::create([
            'distribuidora_id' => $pedido->distribuidora_id,
            'pedido_id' => $pedido->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $nuevoEstado,
            'cambiado_por_staff_id' => $staffId,
            'comentario' => $comentario,
        ]);

        return $pedido;
    }

    // Aplica el mismo cambio a todos los pedidos de un ciclo de golpe —
    // la usa Paquete C al cerrar/solicitar/recibir un ciclo completo.
    public function cambiarParaCiclo(iterable $pedidos, string $nuevoEstado, ?int $staffId = null, ?string $comentario = null): void
    {
        foreach ($pedidos as $pedido) {
            $this->cambiar($pedido, $nuevoEstado, $staffId, $comentario);
        }
    }
}