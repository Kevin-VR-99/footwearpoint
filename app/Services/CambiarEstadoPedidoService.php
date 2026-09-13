<?php

namespace App\Services;

use App\Models\DistribuidoraStaff;
use App\Models\HistorialEstadoPedido;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Notificacion\NotificarCambioEstadoPedidoAction;
use Illuminate\Support\Facades\Auth;

class CambiarEstadoPedidoService
{
    // Único lugar del proyecto que debe cambiar pedidos.estado.
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

        app(NotificarCambioEstadoPedidoAction::class)
            ->ejecutar($pedido, $estadoAnterior, $nuevoEstado);

        app(RegistrarAuditoriaAction::class)->ejecutar(
            'pedido.cambio_estado',
            'pedido',
            $pedido->id,
            ['estado' => $estadoAnterior],
            [
                'estado' => $nuevoEstado,
                'comentario' => $comentario,
            ]
        );

        return $pedido;
    }

    public function cambiarParaCiclo(iterable $pedidos, string $nuevoEstado, ?int $staffId = null, ?string $comentario = null): void
    {
        $staffId = $staffId ?? $this->staffIdActual();

        foreach ($pedidos as $pedido) {
            $this->cambiar($pedido, $nuevoEstado, $staffId, $comentario);
        }
    }

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