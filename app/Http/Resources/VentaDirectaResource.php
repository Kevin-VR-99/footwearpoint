<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recibe un App\Services\VentaDirecta\ResultadoVentaDirecta.
 */
class VentaDirectaResource extends JsonResource
{
    /** Tasa documentada en el Plan de Tareas. El modelo no tiene columna de tasa. */
    private const TASA_IVA = 0.16;

    public function toArray(Request $request): array
    {
        $venta = $this->venta;
        $pago = $this->pago;
        $total = (float) $venta->total;

        // OJO: base_gravable e IVA son SOLO para mostrar. No corresponden a
        // ninguna columna: ventas_directas.subtotal ya incluye IVA.
        $baseGravable = round($total / (1 + self::TASA_IVA), 2);

        return [
            'id' => $venta->id,
            'folio' => $venta->folio,
            'fecha_venta' => $venta->fecha_venta?->toIso8601String(),
            'sucursal_id' => (int) $venta->sucursal_id,
            'cliente_directo_id' => $venta->cliente_directo_id !== null
                ? (int) $venta->cliente_directo_id
                : null,
            'estado' => $venta->estado,
            'registrada_por_staff_id' => (int) $venta->registrada_por_staff_id,

            // Importes reales de la base de datos (IVA incluido).
            'subtotal' => (float) $venta->subtotal,
            'descuento' => (float) $venta->descuento,
            'total' => $total,

            // Desglose calculado, nunca almacenado.
            'desglose_iva' => [
                'etiqueta_base' => 'Base gravable',
                'base_gravable' => $baseGravable,
                'etiqueta_iva' => 'IVA (16%)',
                'iva' => round($total - $baseGravable, 2),
            ],

            'pago' => [
                'id' => $pago->id,
                'folio' => $pago->folio,
                'tipo' => $pago->tipo,
                'direccion' => $pago->direccion,
                'metodo' => $pago->metodo,
                'monto' => (float) $pago->monto,
                'estado' => $pago->estado,
                'fecha_pago' => $pago->fecha_pago?->toIso8601String(),
            ],

            'lineas' => VentaDirectaDetalleResource::collection($this->detalles),
        ];
    }
}