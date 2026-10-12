<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'folio'          => $this->folio,
            'monto_original' => (float) $this->monto_original,
            'saldo_actual'   => (float) $this->saldo_actual,
            'fecha_emision'  => optional($this->fecha_emision)->toIso8601String(),
            'fecha_vencimiento' => optional($this->fecha_vencimiento)->toIso8601String(),
            'estado'         => $this->estado,
            // TG-122: vence dentro del margen de aviso (VALES_DIAS_AVISO_VENCIMIENTO).
            'por_vencer'     => $this->estaPorVencer(),
            'motivo'         => $this->motivo,
            'propietario'    => [
                'tipo'   => $this->cliente_directo_id ? 'cliente_directo' : 'revendedor',
                'id'     => $this->cliente_directo_id ?? $this->revendedor_distribuidora_id,
                'nombre' => $this->clienteDirecto?->nombre
                    ?? $this->revendedorAfiliacion?->revendedor?->nombre
                    ?? null,
            ],
            'creado_por_staff_id' => $this->creado_por_staff_id,
        ];
    }
}