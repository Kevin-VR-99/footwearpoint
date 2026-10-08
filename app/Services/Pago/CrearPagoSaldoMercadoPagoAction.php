<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\MercadoPago\MercadoPagoException;

/**
 * TG-227 (G8) — El cliente directo paga el saldo de su pedido con Checkout
 * Pro, completo y cuando su pedido ya llegó a la distribuidora. Las reglas
 * están en CrearPagoPedidoMercadoPagoAction.
 */
class CrearPagoSaldoMercadoPagoAction
{
    public function __construct(private readonly CrearPagoPedidoMercadoPagoAction $cobrar)
    {
    }

    /**
     * @return array{pago: Pago, init_point: string, reutilizado: bool, moneda: string}
     *
     * @throws MercadoPagoException con un mensaje listo para el cliente.
     */
    public function ejecutar(Pedido $pedido): array
    {
        return $this->cobrar->ejecutar($pedido, CrearPagoPedidoMercadoPagoAction::SALDO);
    }
}
