<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoCampanaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'producto_id'               => $this->producto_id,
            'campana_id'                => $this->campana_id,
            'codigo_catalogo'           => $this->codigo_catalogo,
            // Un solo precio: el de menudeo del catálogo (D8). El mayoreo de
            // cada distribuidora se calcula con PrecioEfectivo.
            'precio_publico'            => (float) $this->precio_publico,
            'activo'                    => (bool) $this->activo,
        ];
    }
}
