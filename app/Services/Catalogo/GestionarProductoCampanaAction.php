<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\Producto;
use App\Models\ProductoCampana;

class GestionarProductoCampanaAction
{
    /**
     * DECISIÓN PROVISIONAL MÍA: valido que el producto y la campaña sean
     * de la MISMA marca antes de publicar (no tendría sentido publicar un
     * producto Nike dentro de una campaña de Adidas). No viene escrito
     * así en ningún documento — es una regla de consistencia que agregué
     * yo. Confirma con el equipo si esta restricción es correcta.
     */
    public function crear(array $datos): ProductoCampana
    {
        $producto = Producto::findOrFail($datos['producto_id']);
        $campana = Campana::findOrFail($datos['campana_id']);

        if ($producto->marca_id !== $campana->marca_id) {
            throw new OperacionInvalidaException(
                'El producto y la campaña deben pertenecer a la misma marca.',
                409
            );
        }

        return ProductoCampana::create([
            'producto_id'               => $datos['producto_id'],
            'campana_id'                => $datos['campana_id'],
            'codigo_catalogo'           => $datos['codigo_catalogo'],
            'precio_mayorista'          => $datos['precio_mayorista'],
            'precio_minorista_sugerido' => $datos['precio_minorista_sugerido'],
            'estado_disponibilidad'     => $datos['estado_disponibilidad'] ?? 'bajo_pedido',
            'publicado'                 => $datos['publicado'] ?? false,
        ]);
    }

    public function actualizar(ProductoCampana $productoCampana, array $datos): ProductoCampana
    {
        // producto_id/campana_id nunca llegan aquí (el Form Request los
        // bloquea en edición).
        $productoCampana->fill($datos);
        $productoCampana->save();

        return $productoCampana->fresh();
    }
}
