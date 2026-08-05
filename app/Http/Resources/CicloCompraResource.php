<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Recibe un App\Services\Ciclo\ResultadoCiclo.
 */
class CicloCompraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ciclo = $this->ciclo;

        return [
            'id' => $ciclo->id,
            'nombre' => $ciclo->nombre,
            'estado' => $ciclo->estado,
            'configuracion_ciclo_id' => $ciclo->configuracion_ciclo_id !== null
                ? (int) $ciclo->configuracion_ciclo_id
                : null,

            'fecha_apertura' => $this->fechaHora($ciclo->fecha_apertura),
            'fecha_cierre' => $this->fechaHora($ciclo->fecha_cierre),
            'fecha_solicitud_fabrica' => $this->fechaHora($ciclo->fecha_solicitud_fabrica),
            'fecha_estimada_llegada' => $ciclo->fecha_estimada_llegada !== null
                ? Carbon::parse($ciclo->fecha_estimada_llegada)->toDateString()
                : null,
            'fecha_recepcion' => $this->fechaHora($ciclo->fecha_recepcion),

            'total_pedidos' => $this->pedidos->count(),
            'pedidos' => CicloPedidoResource::collection($this->pedidos),

            // Resumen por variante y cantidad para la orden a fabrica.
            'consolidado' => $this->consolidado,
        ];
    }

    private function fechaHora($valor): ?string
    {
        return $valor !== null ? Carbon::parse($valor)->toIso8601String() : null;
    }
}