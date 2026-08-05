<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_local_id' => (int) $this->stock_local_id,
            'tipo' => $this->tipo,
            'cantidad' => (int) $this->cantidad,
            'existencia_anterior' => (int) $this->existencia_anterior,
            'existencia_posterior' => (int) $this->existencia_posterior,
            'motivo' => $this->motivo,
            'registrado_por_staff_id' => (int) $this->registrado_por_staff_id,
            'registrado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}