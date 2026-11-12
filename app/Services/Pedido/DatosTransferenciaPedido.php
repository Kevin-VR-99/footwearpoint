<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;

/**
 * Lo que ve quien debe pagar el pedido, si la distribuidora guardó su cuenta.
 * Sin datos, no hay nada que mostrar.
 */
class DatosTransferenciaPedido
{
    public const AVISO = 'FootwearPoint no recibe ni custodia estos pagos';

    /** @return array{banco: string, clabe: string, titular: string, monto: float, concepto: string, aviso: string}|null */
    public function para(Pedido $pedido): ?array
    {
        $config = ConfiguracionDistribuidora::query()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->first();

        if ($config === null || blank($config->clabe) || blank($config->banco) || blank($config->titular_cuenta)) {
            return null;
        }

        $saldo = app(RegistrarPagoPedidoAction::class)->resumen($pedido)['saldo'];

        return [
            'banco' => (string) $config->banco,
            'clabe' => (string) $config->clabe,
            'titular' => (string) $config->titular_cuenta,
            'monto' => (float) $saldo,
            'concepto' => (string) $pedido->folio,
            'aviso' => self::AVISO,
        ];
    }
}
