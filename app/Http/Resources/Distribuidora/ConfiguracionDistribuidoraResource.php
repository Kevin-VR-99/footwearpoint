<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionDistribuidoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'anticipo_por_producto'    => (float) $this->anticipo_por_producto,
            'dias_solicitud_cambio'    => $this->dias_solicitud_cambio,
            'dias_gestion_devolucion'  => $this->dias_gestion_devolucion,
            'dias_vigencia_vale'       => $this->dias_vigencia_vale,
            'dias_maximos_recoleccion' => $this->dias_maximos_recoleccion,
            'moneda'                   => $this->moneda,
            'zona_horaria'             => $this->zona_horaria,
        ];
    }
}
