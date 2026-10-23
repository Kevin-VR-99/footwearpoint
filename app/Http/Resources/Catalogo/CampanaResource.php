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
            'linea_id'     => $this->linea_id,
            'temporada_id' => $this->temporada_id,
            'nombre'       => $this->nombre,
            'descripcion'  => $this->descripcion,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin'    => $this->fecha_fin,
            // Calculado por fecha (activa / borrador = próxima / finalizada).
            'estado'       => $this->estadoPorFecha(),
            'temporada'    => $this->whenLoaded('temporada', fn () => $this->temporada ? [
                'id'           => $this->temporada->id,
                'nombre'       => $this->temporada->nombre,
                'fecha_inicio' => $this->temporada->fecha_inicio?->toDateString(),
                'fecha_fin'    => $this->temporada->fecha_fin?->toDateString(),
                'vigencia'     => $this->temporada->vigencia(),
            ] : null),
            'linea'        => $this->whenLoaded('linea', fn () => [
                'id'     => $this->linea->id,
                'nombre' => $this->linea->nombre,
                'activa' => (bool) $this->linea->activa,
            ]),
        ];
    }
}