<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\MercadoPago\MercadoPagoException;

/**
 * TG-229 (G10) — El cliente mayorista (revendedor en el código) paga con
 * Checkout Pro todo lo que falta de su pedido, a la cuenta de Mercado Pago de
 * la distribuidora. Las reglas están en CrearPagoPedidoMercadoPagoAction.
 */
class CrearPagoMayoristaMercadoPagoAction
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
        return $this->cobrar->ejecutar($pedido, CrearPagoPedidoMercadoPagoAction::MAYORISTA);
    }
}
