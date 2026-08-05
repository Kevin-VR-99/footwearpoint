<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'tipo'         => $this->tipo,
            'titulo'       => $this->titulo,
            'mensaje'      => $this->mensaje,
            'leida'        => $this->leida_at !== null,
            'leida_at'     => optional($this->leida_at)->toIso8601String(),
            'entidad_tipo' => $this->entidad_tipo,
            'entidad_id'   => $this->entidad_id,
            'created_at'   => optional($this->created_at)->toIso8601String(),
        ];
    }
}