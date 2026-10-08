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

            // E3-07 (TG-133): si ya puede entrar a la app, y con qué correo.
            // El correo de la cuenta puede ser distinto al de contacto.
            'tiene_cuenta'    => $this->revendedor->usuario_id !== null,
            'cuenta_email'    => $this->revendedor->usuario?->email,
        ];
    }
}
