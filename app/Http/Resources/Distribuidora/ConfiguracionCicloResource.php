<?php

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionCicloResource extends JsonResource
{
    /**
     * Asume la relación diasRecepcion() en el modelo ConfiguracionCiclo
     * (hasMany hacia configuracion_ciclo_dias_recepcion). Ver README de este
     * bloque para la lista completa de dependencias a verificar contra Fase 0.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'dia_cierre'             => $this->dia_cierre,
            'hora_cierre'            => $this->hora_cierre,
            'dia_solicitud_fabrica'  => $this->dia_solicitud_fabrica,
            'dias_estimados_llegada' => $this->dias_estimados_llegada,
            'activa'                 => (bool) $this->activa,
            'dias_recepcion'         => $this->diasRecepcion->pluck('dia_semana')->values(),
        ];
    }
}
