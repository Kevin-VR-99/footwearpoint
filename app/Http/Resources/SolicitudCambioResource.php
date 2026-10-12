<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** TG-254 — Una solicitud de cambio de producto. */
class SolicitudCambioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $linea = $this->pedido_detalle_id ? $this->pedidoDetalle : $this->ventaDetalle;

        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'origen' => $this->pedido_detalle_id ? 'pedido' : 'venta_directa',
            'pedido_detalle_id' => $this->pedido_detalle_id,
            'venta_detalle_id' => $this->venta_detalle_id,
            'pedido_id' => $this->pedidoDetalle?->pedido_id,
            'producto' => $linea ? [
                'nombre' => $linea->producto_nombre,
                'modelo' => $linea->modelo,
                'talla' => $linea->talla,
                'color' => $linea->color,
                'cantidad' => (int) $linea->cantidad,
            ] : null,
            'propietario' => [
                'tipo' => $this->cliente_directo_id ? 'cliente_directo' : 'revendedor',
                'id' => $this->cliente_directo_id ?? $this->revendedor_distribuidora_id,
            ],
            'motivo' => $this->motivo,
            'resolucion' => $this->resolucion,
            'fecha_entrega_original' => optional($this->fecha_entrega_original)->toIso8601String(),
            'fecha_solicitud' => optional($this->fecha_solicitud)->toIso8601String(),
            'fecha_limite_solicitud' => optional($this->fecha_limite_solicitud)->toIso8601String(),
            'dias_solicitud_aplicados' => (int) $this->dias_solicitud_aplicados,
            'vale_generado_id' => $this->vale_generado_id,
        ];
    }
}
