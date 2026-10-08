<?php

namespace App\Http\Resources\Catalogo;

use App\Services\Catalogo\PrecioEfectivo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un producto del catálogo, como lo recibe la app (E4-05).
 *
 * Los campos son los mismos desde el Sprint 3 a propósito, para no romper la
 * app, aunque por dentro todo cambió (TG-213):
 *
 *   - precio_minorista_sugerido es el precio de catálogo, igual para todas las
 *     distribuidoras (D8);
 *   - precio_mayorista es el que calcula esta distribuidora, con su descuento
 *     general o con el precio propio que le puso al producto;
 *   - la línea ya no es del producto, sale de su temporada (D5).
 *
 * El precio mayorista solo se le manda a quien compra a mayoreo (el
 * revendedor) y al personal de la distribuidora. Al cliente directo no se le
 * manda: es el precio de costo de otro.
 */
class CatalogoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $puedeVerMayorista = $request->user()?->hasAnyRole([
            'admin_general',
            'admin_distribuidora',
            'empleado',
            'revendedor',
        ]) ?? false;

        $precios = app(PrecioEfectivo::class);
        $linea = $this->campana?->linea;

        return [
            'id' => $this->id,
            'producto' => [
                'id'       => $this->producto->id,
                'modelo'   => $this->producto->modelo,
                'nombre'   => $this->producto->nombre,
                'marca'    => $this->producto->marca ? [
                    'id'     => $this->producto->marca->id,
                    'nombre' => $this->producto->marca->nombre,
                ] : null,
                'linea'    => $linea ? [
                    'id'     => $linea->id,
                    'nombre' => $linea->nombre,
                ] : null,
                'categoria' => $this->producto->categoria ? [
                    'id'     => $this->producto->categoria->id,
                    'nombre' => $this->producto->categoria->nombre,
                ] : null,
            ],
            'codigo_catalogo'           => $this->codigo_catalogo,
            'precio_minorista_sugerido' => $precios->menudeo($this->resource),
            'precio_mayorista'          => $this->when($puedeVerMayorista, fn () => $precios->mayoreo($this->resource)),
            'imagenes' => ImagenProductoCampanaResource::collection($this->whenLoaded('imagenes')),
            'variantes' => $this->disponibilidadPorVariante->map(fn ($disponibilidad) => [
                'variante_id'            => $disponibilidad->variante_id,
                'sku'                    => $disponibilidad->variante->sku,
                'talla'                  => $disponibilidad->variante->talla->valor,
                'color'                  => $disponibilidad->variante->color->nombre,
                'nombre_color_comercial' => $disponibilidad->variante->nombre_color_comercial,
                'disponibilidad'         => $disponibilidad->estado,
            ]),
        ];
    }
}
