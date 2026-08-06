<?php

namespace App\Services\Notificacion;

use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pedido;

class NotificarCambioEstadoPedidoAction
{
    public function ejecutar(Pedido $pedido, string $estadoAnterior, string $estadoNuevo): void
    {
        if ($estadoAnterior === $estadoNuevo) {
            return;
        }

        $titulo = 'Pedido '.$pedido->folio.' → '.$estadoNuevo;
        $mensaje = sprintf(
            'El pedido %s cambió de «%s» a «%s».',
            $pedido->folio,
            $estadoAnterior,
            $estadoNuevo
        );

        $staffs = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->get(['usuario_id']);

        foreach ($staffs as $staff) {
            Notificacion::create([
                'usuario_id'       => $staff->usuario_id,
                'distribuidora_id' => $pedido->distribuidora_id,
                'tipo'             => 'pedido_estado',
                'titulo'           => $titulo,
                'mensaje'          => $mensaje,
                'leida_at'         => null,
                'entidad_tipo'     => 'pedido',
                'entidad_id'       => $pedido->id,
            ]);
        }
    }
}