<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'marca_id'     => $this->marca_id,
            'categoria_id' => $this->categoria_id,
            'modelo'       => $this->modelo,
            'nombre'       => $this->nombre,
            'descripcion'  => $this->descripcion,
            'activo'       => (bool) $this->activo,
        ];
    }
}
