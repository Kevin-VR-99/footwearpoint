<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerfilResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'nombre_comercial'    => $this->nombre_comercial,
            'descripcion_publica' => $this->descripcion_publica,
            'direccion_publica'   => $this->direccion_publica,
            'telefono_publico'    => $this->telefono_publico,
            'email_publico'       => $this->email_publico,
            'horario_publico'     => $this->horario_publico,
            'logotipo_url'        => $this->logotipo_url,
            'marketplace_visible' => (bool) $this->marketplace_visible,
            'estado'              => $this->estado,
        ];
    }
}
