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

            // E3-07 (TG-133): si ya puede entrar a la app, y con qué correo.
            // El correo de la cuenta puede ser distinto al de contacto.
            'tiene_cuenta'        => $this->usuario_id !== null,
            'cuenta_email'        => $this->usuario?->email,
        ];
    }
}
