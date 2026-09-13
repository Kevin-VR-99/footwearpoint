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
            $this->crear(
                (int) $staff->usuario_id,
                (int) $pedido->distribuidora_id,
                'pedido_estado',
                $titulo,
                $mensaje,
                $pedido->id
            );
        }

        if (in_array($estadoNuevo, ['recibido_distribuidora', 'listo_entrega'], true)) {
            $this->notificarLlegadaAlDueno($pedido, $estadoNuevo);
        }
    }

    protected function notificarLlegadaAlDueno(Pedido $pedido, string $estadoNuevo): void
    {
        $pedido->loadMissing(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        $usuarioId = null;

        if ($pedido->tipo === 'cliente_directo') {
            $usuarioId = $pedido->clienteDirecto?->usuario_id;
        } else {
            $usuarioId = $pedido->revendedorAfiliacion?->revendedor?->usuario_id;
        }

        if (! $usuarioId) {
            return;
        }

        $titulo = 'Tu pedido '.$pedido->folio.' ya está en sucursal';
        $mensaje = $estadoNuevo === 'listo_entrega'
            ? 'Ya puedes pasar a recoger y liquidar el saldo pendiente.'
            : 'La mercancía de tu pedido llegó a la distribuidora. Te avisaremos cuando esté listo para entrega.';

        $this->crear(
            (int) $usuarioId,
            (int) $pedido->distribuidora_id,
            'pedido_llegada',
            $titulo,
            $mensaje,
            $pedido->id
        );
    }

    protected function crear(
        int $usuarioId,
        int $distribuidoraId,
        string $tipo,
        string $titulo,
        string $mensaje,
        int $pedidoId
    ): void {
        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => $distribuidoraId,
            'tipo'             => $tipo,
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => $pedidoId,
        ]);
    }
}