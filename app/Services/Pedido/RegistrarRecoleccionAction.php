<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use App\Services\CambiarEstadoPedidoService;
use Illuminate\Validation\ValidationException;

class RegistrarRecoleccionAction
{
    public function __construct(
        protected CambiarEstadoPedidoService $cambiarEstado,
        protected RegistrarPagoPedidoAction $pagos,
    ) {}

    public function ejecutar(Pedido $pedido): Pedido
    {
        if ($pedido->estado !== 'listo_entrega') {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se registra recolección de un pedido listo para entrega.'],
            ]);
        }

        $resumen = $this->pagos->resumen($pedido);

        if ($resumen['saldo'] > 0.009) {
            throw ValidationException::withMessages([
                'pedido' => ['No se puede entregar: hay saldo pendiente ($'.number_format($resumen['saldo'], 2).').'],
            ]);
        }

        $pedido->fecha_entrega = now();
        $pedido->save();

        $pedido = $this->cambiarEstado->cambiar(
            $pedido,
            'entregado',
            null,
            'Recolección registrada'
        );

        return $pedido->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle', 'pagos']);
    }
}