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
            // Auditoría 21-oct-2026: versiones WebP (si la foto es vieja, la original).
            'url_miniatura' => $this->resource->urlMiniatura(),
            'url_mediana'   => $this->resource->urlMediana(),
            'orden'        => $this->orden,
            'es_principal' => (bool) $this->es_principal,
        ];
    }
}
