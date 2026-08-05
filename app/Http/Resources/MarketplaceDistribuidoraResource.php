<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceDistribuidoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'nombre_comercial'    => $this->nombre_comercial,
            'logotipo_url'        => $this->logotipo_url,
            'descripcion_publica' => $this->descripcion_publica,
            'telefono_publico'    => $this->telefono_publico,
            'email_publico'       => $this->email_publico,
            'direccion_publica'   => $this->direccion_publica,
            'horario_publico'     => $this->horario_publico,
        ];
    }
}