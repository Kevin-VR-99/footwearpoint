<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VentaDirectaDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variante_id' => (int) $this->variante_id,
            'producto_campana_id' => $this->producto_campana_id !== null
                ? (int) $this->producto_campana_id
                : null,
            'producto_nombre' => $this->producto_nombre,
            'modelo' => $this->modelo,
            'talla' => $this->talla,
            'color' => $this->color,
            'cantidad' => (int) $this->cantidad,
            'precio_unitario' => (float) $this->precio_unitario,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}