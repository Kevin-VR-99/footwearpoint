<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImagenProductoCampanaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'url'          => $this->url,
            'orden'        => $this->orden,
            'es_principal' => (bool) $this->es_principal,
        ];
    }
}
