<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista minima de un pedido dentro de un ciclo.
 *
 * Deliberadamente NO se llama PedidoResource: ese nombre le corresponde al
 * Paquete D, que expone el pedido completo. Aqui solo va lo que la pantalla
 * del ciclo necesita mostrar.
 */
class CicloPedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'total' => (float) $this->total,
        ];
    }
}