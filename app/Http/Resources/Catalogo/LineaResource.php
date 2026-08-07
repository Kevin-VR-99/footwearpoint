<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LineaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'campana_id'  => $this->campana_id,
            'nombre'      => $this->nombre,
            'descripcion' => $this->descripcion,
            'activa'      => (bool) $this->activa,
            'campana'     => $this->whenLoaded('campana', fn () => [
                'id'     => $this->campana->id,
                'nombre' => $this->campana->nombre,
                'estado' => $this->campana->estado,
            ]),
            'marcas'      => $this->whenLoaded('marcas', fn () => $this->marcas->map(fn ($m) => [
                'id'     => $m->id,
                'nombre' => $m->nombre,
                'activa' => (bool) $m->activa,
            ])->values()),
        ];
    }
}