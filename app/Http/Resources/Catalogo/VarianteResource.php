<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VarianteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'producto_id'             => $this->producto_id,
            'talla_id'                => $this->talla_id,
            'color_id'                => $this->color_id,
            'nombre_color_comercial'  => $this->nombre_color_comercial,
            'sku'                     => $this->sku,
            'activa'                  => (bool) $this->activa,
        ];
    }
}
