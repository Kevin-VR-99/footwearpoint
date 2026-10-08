<?php

namespace App\Support;

use App\Models\Pago;

/**
 * Folio consecutivo de los pagos de pedido: PAG-AAAAMMDD-0001, por
 * distribuidora y por día.
 *
 * TG-226 (G7): se movió aquí desde RegistrarPagoPedidoAction para que el pago
 * en mostrador y el anticipo con Mercado Pago usen la misma numeración.
 *
 * Sin scope de tenant a propósito (igual que antes): el folio es único por
 * distribuidora, así que la consulta ya filtra por distribuidora_id a mano y
 * debe funcionar también cuando no hay sesión (por ejemplo, en el aviso de
 * Mercado Pago de G9).
 */
final class FolioPago
{
    public static function siguiente(int $distribuidoraId): string
    {
        $prefijo = 'PAG-'.now()->format('Ymd').'-';

        $ultimo = Pago::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo.'%')
            ->orderByDesc('id')
            ->value('folio');

        $secuencia = 1;
        if ($ultimo && preg_match('/-(\d+)$/', $ultimo, $m)) {
            $secuencia = (int) $m[1] + 1;
        }

        return $prefijo.str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
    }
}
