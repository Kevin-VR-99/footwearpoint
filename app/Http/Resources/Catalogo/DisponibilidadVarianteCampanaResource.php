<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisponibilidadVarianteCampanaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'producto_campana_id'  => $this->producto_campana_id,
            'variante_id'          => $this->variante_id,
            'estado'               => $this->estado,
            'fecha_verificacion'   => $this->fecha_verificacion,
        ];
    }
}
