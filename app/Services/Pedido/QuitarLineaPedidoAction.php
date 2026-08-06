<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuitarLineaPedidoAction
{
    public function ejecutar(Pedido $pedido, int $lineaId): Pedido
    {
        if ($pedido->estado !== 'borrador') {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se pueden quitar líneas de pedidos en borrador.'],
            ]);
        }

        $linea = PedidoDetalle::query()
            ->where('pedido_id', $pedido->id)
            ->where('id', $lineaId)
            ->first();

        if (! $linea) {
            throw ValidationException::withMessages([
                'linea' => ['La línea no existe en este pedido.'],
            ]);
        }

        return DB::transaction(function () use ($pedido, $linea) {
            $linea->delete();

            $nuevoSubtotal = (float) $pedido->detalle()->sum('subtotal');
            $pedido->subtotal = $nuevoSubtotal;
            $pedido->total = $nuevoSubtotal;
            $pedido->save();

            return $pedido->fresh([
                'clienteDirecto',
                'revendedorAfiliacion.revendedor',
                'detalle',
            ]);
        });
    }
}