<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarcaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'nombre'       => $this->nombre,
            'logotipo_url' => $this->logotipo_url,
            'descripcion'  => $this->descripcion,
            'activa'       => (bool) $this->activa,
        ];
    }
}
