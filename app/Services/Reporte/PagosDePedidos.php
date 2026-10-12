<?php

namespace App\Services\Reporte;

use App\Models\Pago;
use App\Models\PedidoDetalle;
use App\Models\ValeMovimiento;

/**
 * Lo pagado y lo requerido de anticipo de muchos pedidos a la vez, con pocas
 * consultas agrupadas (TG-251, TG-252).
 *
 * Misma regla que RegistrarPagoPedidoAction::resumen() para un pedido:
 * pagado = pagos de entrada aplicados + vales aplicados (TG-167).
 */
class PagosDePedidos
{
    /**
     * @param  array<int>  $pedidoIds
     * @return array<int, float> pagado por pedido_id (solo los que tienen algo)
     */
    public function pagadoPorPedido(array $pedidoIds): array
    {
        if ($pedidoIds === []) {
            return [];
        }

        $pagado = [];

        $pagos = Pago::query()
            ->whereIn('pedido_id', $pedidoIds)
            ->where('estado', 'aplicado')
            ->where('direccion', 'entrada')
            ->selectRaw('pedido_id, SUM(monto) as suma')
            ->groupBy('pedido_id')
            ->pluck('suma', 'pedido_id');

        $vales = ValeMovimiento::query()
            ->whereIn('pedido_id', $pedidoIds)
            ->where('tipo', 'aplicacion')
            ->selectRaw('pedido_id, SUM(monto) as suma')
            ->groupBy('pedido_id')
            ->pluck('suma', 'pedido_id');

        foreach ([$pagos, $vales] as $sumas) {
            foreach ($sumas as $pedidoId => $suma) {
                $pagado[(int) $pedidoId] = round(($pagado[(int) $pedidoId] ?? 0) + (float) $suma, 2);
            }
        }

        return $pagado;
    }

    /**
     * @param  array<int>  $pedidoIds
     * @return array<int, float> anticipo requerido por pedido_id
     */
    public function anticipoRequeridoPorPedido(array $pedidoIds): array
    {
        if ($pedidoIds === []) {
            return [];
        }

        return PedidoDetalle::query()
            ->whereIn('pedido_id', $pedidoIds)
            ->selectRaw('pedido_id, SUM(anticipo_requerido) as suma')
            ->groupBy('pedido_id')
            ->pluck('suma', 'pedido_id')
            ->map(fn ($s) => round((float) $s, 2))
            ->all();
    }
}
