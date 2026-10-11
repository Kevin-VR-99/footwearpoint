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
            'nombre'       => $this->nombre,
            'descripcion'  => $this->descripcion,
            'logotipo_url' => $this->logotipo_url,
            'activa'       => (bool) $this->activa,
            // Cada línea tiene sus temporadas (D1).
            'campanas'     => $this->whenLoaded('campanas', fn () => $this->campanas->map(fn ($c) => [
                'id'     => $c->id,
                'nombre' => $c->nombre,
                'estado' => $c->estado,
            ])->values()),
            'marcas'      => $this->whenLoaded('marcas', fn () => $this->marcas->map(fn ($m) => [
                'id'     => $m->id,
                'nombre' => $m->nombre,
                'activa' => (bool) $m->activa,
            ])->values()),
        ];
    }
}