<?php

namespace App\Services;

use App\Models\DistribuidoraStaff;
use App\Models\HistorialEstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;

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
            'cambiado_por_staff_id' => $staffId ?? $this->staffIdActual(),
            'comentario' => $comentario,
        ]);

        return $pedido;
    }

    public function cambiarParaCiclo(iterable $pedidos, string $nuevoEstado, ?int $staffId = null, ?string $comentario = null): void
    {
        $staffId = $staffId ?? $this->staffIdActual();

        foreach ($pedidos as $pedido) {
            $this->cambiar($pedido, $nuevoEstado, $staffId, $comentario);
        }
    }

    // Si nadie pasó un staff_id a mano, lo busca solo a partir del usuario
    // que inició sesión (mismo patrón que App\Support\Tenant::id()).
    protected function staffIdActual(): ?int
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return null;
        }

        return DistribuidoraStaff::where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->value('id');
    }
}