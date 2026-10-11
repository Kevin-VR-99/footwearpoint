<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;

/**
 * Cuánto anticipo pide una línea de pedido (E8-02, TG-215).
 *
 * El anticipo es un monto fijo por par que configura cada distribuidora
 * (anticipo_por_producto, $100 por omisión). Dos reglas de negocio:
 *
 *  1. Nunca puede ser mayor que el precio del par. Si un par cuesta $80 y el
 *     anticipo configurado es $100, el anticipo de ese par es $80: cobrar de
 *     adelanto más de lo que cuesta no tiene sentido. Se mide contra el precio
 *     que de verdad se cobra, aunque el personal lo haya cambiado.
 *  2. Solo aplica a pedidos de cliente directo. El revendedor no da anticipo:
 *     paga el total en mostrador, así que su anticipo requerido es 0.
 *
 * Es el único lugar donde se calcula: lo usa quien agrega líneas al pedido.
 */
class AnticipoDeLinea
{
    public function calcular(Pedido $pedido, float $precioUnitario, int $cantidad): float
    {
        if ($pedido->tipo !== 'cliente_directo') {
            return 0.0;
        }

        $configurado = (float) (ConfiguracionDistribuidora::query()->value('anticipo_por_producto') ?? 0);

        $porPar = min($configurado, round($precioUnitario, 2));

        return round(max(0, $porPar) * $cantidad, 2);
    }
}
