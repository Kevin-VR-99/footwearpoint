<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** TG-254 — Una solicitud de cambio de producto. */
class SolicitudCambioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $linea = $this->pedido_detalle_id ? $this->pedidoDetalle : $this->ventaDetalle;

        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'origen' => $this->pedido_detalle_id ? 'pedido' : 'venta_directa',
            'pedido_detalle_id' => $this->pedido_detalle_id,
            'venta_detalle_id' => $this->venta_detalle_id,
            'pedido_id' => $this->pedidoDetalle?->pedido_id,
            'producto' => $linea ? [
                'nombre' => $linea->producto_nombre,
                'modelo' => $linea->modelo,
                'talla' => $linea->talla,
                'color' => $linea->color,
                'cantidad' => (int) $linea->cantidad,
            ] : null,
            'propietario' => [
                'tipo' => $this->cliente_directo_id ? 'cliente_directo' : 'revendedor',
                'id' => $this->cliente_directo_id ?? $this->revendedor_distribuidora_id,
            ],
            'motivo' => $this->motivo,
            'resolucion' => $this->resolucion,
            'fecha_entrega_original' => optional($this->fecha_entrega_original)->toIso8601String(),
            'fecha_solicitud' => optional($this->fecha_solicitud)->toIso8601String(),
            'fecha_limite_solicitud' => optional($this->fecha_limite_solicitud)->toIso8601String(),
            'dias_solicitud_aplicados' => (int) $this->dias_solicitud_aplicados,
            'vale_generado_id' => $this->vale_generado_id,
            'estado_etiqueta' => \App\Models\SolicitudCambio::etiqueta((string) $this->estado),
            // Solo en el detalle (GET /solicitudes-cambio/{id}).
            'timeline' => $this->whenLoaded('historial', fn () => $this->lineaDeTiempo()),
        ];
    }

    private const ZONA = 'America/Mexico_City';

    /**
     * Quién dio el paso: el nombre de quien lo resolvió en la distribuidora,
     * «Cliente» para la solicitud inicial, o «Distribuidora» si no se sabe.
     * Nunca correos ni datos de contacto.
     */
    private function quien($paso): string
    {
        if ($paso->staff_id !== null) {
            $nombre = $paso->relationLoaded('staff') ? $paso->staff?->usuario?->nombre : null;

            return $nombre ? $nombre.' (distribuidora)' : 'Distribuidora';
        }

        return $paso->estado_anterior === null ? 'Cliente' : 'Distribuidora';
    }

    private function lineaDeTiempo(): array
    {
        $pasos = $this->historial->map(fn ($h) => [
            'id'              => (int) $h->id,
            'estado'          => $h->estado_nuevo,
            'etiqueta'        => \App\Models\SolicitudCambio::etiqueta($h->estado_nuevo),
            'estado_anterior' => $h->estado_anterior,
            'nota'            => $h->nota,
            'fecha'           => optional($h->created_at)->toIso8601String(),
            // La hora de la Ciudad de México, lista para mostrar.
            'fecha_cdmx'      => optional($h->created_at)->copy()?->timezone(self::ZONA)->format('d/m/Y H:i'),
            'quien'           => $this->quien($h),
        ])->values()->all();

        // Solicitudes de antes del historial: el primer paso sale de la solicitud.
        if ($pasos === []) {
            $pasos[] = ['id' => null, 'estado' => 'solicitada', 'etiqueta' => 'Solicitada', 'estado_anterior' => null, 'nota' => $this->motivo,
                'fecha' => optional($this->fecha_solicitud)->toIso8601String(),
                'fecha_cdmx' => optional($this->fecha_solicitud)->copy()?->timezone(self::ZONA)->format('d/m/Y H:i'),
                'quien' => 'Cliente'];
            if ($this->estado !== 'solicitada') {
                $pasos[] = ['id' => null, 'estado' => $this->estado, 'etiqueta' => \App\Models\SolicitudCambio::etiqueta((string) $this->estado),
                    'estado_anterior' => 'solicitada', 'nota' => $this->resolucion, 'fecha' => optional($this->updated_at)->toIso8601String(),
                    'fecha_cdmx' => optional($this->updated_at)->copy()?->timezone(self::ZONA)->format('d/m/Y H:i'),
                    'quien' => 'Distribuidora'];
            }
        }

        return $pasos;
    }
}
