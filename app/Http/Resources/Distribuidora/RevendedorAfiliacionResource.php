<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevendedorAfiliacionResource extends JsonResource
{
    /**
     * Asume la relación RevendedorDistribuidora::revendedor() (belongsTo
     * Revendedor). Ver LEEME de este bloque para la lista de dependencias.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'nombre'          => $this->revendedor->nombre,
            'telefono'        => $this->revendedor->telefono,
            'email'           => $this->revendedor->email,
            'codigo_interno'  => $this->codigo_interno,
            'estado'          => $this->estado,
            'fecha_alta'      => $this->fecha_alta,
            'notas'           => $this->notas,
        ];
    }
}
