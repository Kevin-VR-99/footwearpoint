<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use App\Services\CambiarEstadoPedidoService;
use Illuminate\Validation\ValidationException;

class EnviarPedidoAction
{
    public function __construct(
        protected CambiarEstadoPedidoService $cambiarEstado
    ) {}

    public function ejecutar(Pedido $pedido): Pedido
    {
        if ($pedido->estado !== 'borrador') {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se pueden enviar pedidos en borrador.'],
            ]);
        }

        if ($pedido->detalle()->count() < 1) {
            throw ValidationException::withMessages([
                'pedido' => ['El pedido debe tener al menos una línea para enviarlo.'],
            ]);
        }

        $pedido = $this->cambiarEstado->cambiar(
            $pedido,
            'colocado',
            null,
            'Pedido enviado (colocado) desde captura'
        );

        if ($pedido->fecha_colocacion === null) {
            $pedido->fecha_colocacion = now();
            $pedido->save();
        }

        return $pedido->fresh([
            'clienteDirecto',
            'revendedorAfiliacion.revendedor',
            'detalle',
        ]);
    }
}