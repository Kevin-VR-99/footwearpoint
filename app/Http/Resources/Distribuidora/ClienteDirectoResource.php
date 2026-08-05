<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteDirectoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'nombre'              => $this->nombre,
            'telefono'            => $this->telefono,
            'email'               => $this->email,
            'direccion_contacto'  => $this->direccion_contacto,
            'notas'               => $this->notas,
            'estado'              => $this->estado,
        ];
    }
}
