<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'folio'     => $this->folio,
            'tipo'      => $this->tipo,
            'estado'    => $this->estado,
            'subtotal'  => (float) $this->subtotal,
            'total'     => (float) $this->total,
            'sucursal_id' => $this->sucursal_id,
            'propietario' => [
                'tipo' => $this->tipo,
                'id'   => $this->cliente_directo_id ?? $this->revendedor_distribuidora_id,
                'nombre' => $this->clienteDirecto?->nombre
                    ?? $this->revendedorAfiliacion?->revendedor?->nombre
                    ?? null,
            ],
            'ciclo_compra_id' => $this->ciclo_compra_id,
            'fecha_colocacion' => optional($this->fecha_colocacion)->toIso8601String(),
            'observaciones'    => $this->observaciones,
            'capturado_por_staff_id' => $this->capturado_por_staff_id,
            'lineas' => $this->whenLoaded('detalle', function () {
                return $this->detalle->map(fn ($l) => [
                    'id'               => $l->id,
                    'producto_nombre'  => $l->producto_nombre,
                    'modelo'           => $l->modelo,
                    'talla'            => $l->talla,
                    'color'            => $l->color,
                    'cantidad'         => $l->cantidad,
                    'precio_unitario'  => (float) $l->precio_unitario,
                    'subtotal'         => (float) $l->subtotal,
                    'anticipo_requerido' => (float) $l->anticipo_requerido,
                    'estado_surtido'   => $l->estado_surtido,
                    'variante_id'      => $l->variante_id,
                    'producto_campana_id' => $l->producto_campana_id,
                ]);
            }),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}