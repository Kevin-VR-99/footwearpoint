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
            'precio_mayorista'          => (float) $this->precio_mayorista,
            'precio_minorista_sugerido' => (float) $this->precio_minorista_sugerido,
            'estado_disponibilidad'     => $this->estado_disponibilidad,
            'publicado'                 => (bool) $this->publicado,
        ];
    }
}
