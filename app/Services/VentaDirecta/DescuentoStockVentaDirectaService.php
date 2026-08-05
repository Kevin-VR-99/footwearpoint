<?php

namespace App\Services\VentaDirecta;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\VentaDirectaDetalle;
use Illuminate\Support\Facades\DB;

/**
 * SERVICIO EXCLUSIVO DE VENTA DIRECTA (E6-03 / E7-01).
 *
 * No debe invocarse desde el flujo de pedidos. Un pedido -de cliente directo
 * o de revendedor- NUNCA modifica stock_local, aunque la variante tenga
 * existencia disponible.
 */
class DescuentoStockVentaDirectaService
{
    /**
     * Bloquea la fila de existencia y valida que alcance, ANTES de escribir
     * cualquier cosa de la venta. Debe llamarse dentro de una transaccion.
     */
    public function bloquearYValidar(
        int $varianteId,
        int $cantidad,
        int $distribuidoraId,
        int $sucursalId
    ): StockLocal {
        $this->exigirTransaccion();

        $stock = StockLocal::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('sucursal_id', $sucursalId)
            ->where('variante_id', $varianteId)
            ->lockForUpdate()
            ->first();

        $disponible = $stock !== null ? (int) $stock->cantidad_disponible : 0;

        if ($stock === null || $disponible < $cantidad) {
            throw new OperacionInvalidaException(
                'No hay existencia suficiente en stock local para completar la venta.',
                409,
                ['lineas' => [
                    "Variante {$varianteId}: existencia disponible {$disponible}, solicitada {$cantidad}.",
                ]]
            );
        }

        return $stock;
    }

    /**
     * Descuenta la existencia y deja el movimiento ligado a la linea de venta.
     */
    public function descontar(
        StockLocal $stock,
        int $cantidad,
        VentaDirectaDetalle $detalle,
        int $staffId
    ): MovimientoStock {
        $this->exigirTransaccion();

        $anterior = (int) $stock->cantidad_disponible;
        $posterior = $anterior - $cantidad;

        if ($posterior < 0) {
            throw new OperacionInvalidaException(
                'No hay existencia suficiente en stock local para completar la venta.',
                409
            );
        }

        $stock->timestamps = false;
        $stock->cantidad_disponible = $posterior;
        $stock->updated_at = now();
        $stock->save();

        $movimiento = new MovimientoStock();
        $movimiento->timestamps = false;
        $movimiento->forceFill([
            'distribuidora_id' => $stock->distribuidora_id,
            'stock_local_id' => $stock->id,
            'tipo' => 'venta',
            'cantidad' => $cantidad,
            'existencia_anterior' => $anterior,
            'existencia_posterior' => $posterior,
            'venta_detalle_id' => $detalle->id,
            'registrado_por_staff_id' => $staffId,
            'motivo' => null,
            'created_at' => now(),
        ])->save();

        return $movimiento;
    }

    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'El descuento de stock de venta directa debe ejecutarse dentro de una transaccion.'
            );
        }
    }
}