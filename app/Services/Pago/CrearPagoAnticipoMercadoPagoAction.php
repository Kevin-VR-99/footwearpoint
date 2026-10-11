<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\MercadoPago\MercadoPagoException;

/**
 * TG-226 (G7) — El cliente directo paga su anticipo con Checkout Pro.
 *
 * Desde TG-227 (G8) la lógica vive en CrearPagoPedidoMercadoPagoAction, que
 * también cobra el saldo; esta clase se queda con la misma firma para quien
 * ya la usa.
 */
class CrearPagoAnticipoMercadoPagoAction
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
        return $this->cobrar->ejecutar($pedido, CrearPagoPedidoMercadoPagoAction::ANTICIPO);
    }
}
