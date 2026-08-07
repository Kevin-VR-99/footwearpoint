<?php

namespace App\Http\Resources\Catalogo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogoResource extends JsonResource
{
    /**
     * DECISIÓN PROVISIONAL MÍA, ya conversada contigo: el documento dice
     * "el precio_mayorista solo se expone a usuarios con rol revendedor",
     * pero los revendedores no tienen cuenta propia este sprint (D2) — con
     * esa regla tal cual, NADIE vería el precio mayorista nunca.
     *
     * En su lugar, muestro el precio mayorista a cualquier cuenta "de la
     * casa" (personal interno + revendedor a futuro), no solo a quien
     * tenga el rol exacto "revendedor". Hoy esto se comporta como
     * "siempre visible" porque solo el personal interno tiene cuenta —
     * pero ya queda listo para cuando el revendedor tenga su propia
     * cuenta en Sprint 3, sin tener que tocar este archivo otra vez.
     */
    public function toArray(Request $request): array
    {
        $puedeVerMayorista = $request->user()?->hasAnyRole([
            'admin_general',
            'admin_distribuidora',
            'empleado',
            'revendedor',
        ]) ?? false;

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
                'linea'    => $this->producto->linea ? [
                    'id'     => $this->producto->linea->id,
                    'nombre' => $this->producto->linea->nombre,
                ] : null,
                'categoria' => $this->producto->categoria ? [
                    'id'     => $this->producto->categoria->id,
                    'nombre' => $this->producto->categoria->nombre,
                ] : null,
            ],
            'codigo_catalogo'           => $this->codigo_catalogo,
            'precio_minorista_sugerido' => (float) $this->precio_minorista_sugerido,
            'precio_mayorista'          => $this->when($puedeVerMayorista, (float) $this->precio_mayorista),
            'imagenes' => ImagenProductoCampanaResource::collection($this->whenLoaded('imagenes')),
            'variantes' => $this->disponibilidadPorVariante->map(fn($disponibilidad) => [
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
