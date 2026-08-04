<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLocalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sucursal_id' => (int) $this->sucursal_id,
            'variante_id' => (int) $this->variante_id,
            'sku' => $this->whenLoaded('variante', fn () => $this->variante->sku),
            'cantidad_disponible' => (int) $this->cantidad_disponible,
            'stock_minimo' => (int) $this->stock_minimo,
            'actualizado_en' => $this->updated_at?->toIso8601String(),
        ];
    }
}