<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampanaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'marca_id'     => $this->marca_id,
            'nombre'       => $this->nombre,
            'descripcion'  => $this->descripcion,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin'    => $this->fecha_fin,
            'estado'       => $this->estado,
            'lineas'       => $this->whenLoaded('lineas', fn () => $this->lineas->map(fn ($l) => [
                'id'     => $l->id,
                'nombre' => $l->nombre,
                'activa' => (bool) $l->activa,
            ])->values()),
        ];
    }
}