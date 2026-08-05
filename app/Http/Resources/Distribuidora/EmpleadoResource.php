<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpleadoResource extends JsonResource
{
    /**
     * Asume la relación DistribuidoraStaff::usuario() (belongsTo Usuario).
     * nombre/email/telefono viven en la tabla global "usuarios", no aquí.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'nombre'     => $this->usuario->nombre,
            'email'      => $this->usuario->email,
            'telefono'   => $this->usuario->telefono,
            'tipo'       => $this->tipo,
            'estado'     => $this->estado,
            'fecha_alta' => $this->fecha_alta,
        ];
    }
}
