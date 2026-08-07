<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\Producto;
use App\Models\ProductoCampana;

class GestionarProductoCampanaAction
{
    public function crear(array $datos): ProductoCampana
    {
        $producto = Producto::with('linea')->findOrFail($datos['producto_id']);
        $campana = Campana::findOrFail($datos['campana_id']);

        if (! $producto->linea_id) {
            throw new OperacionInvalidaException(
                'El producto no tiene línea comercial asignada. Asigna una línea antes de publicarlo en una campaña.',
                409
            );
        }

        if ((int) $producto->linea->campana_id !== (int) $campana->id) {
            throw new OperacionInvalidaException(
                'La línea del producto no pertenece a esa campaña. Publica el producto solo en la campaña de su línea.',
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
        $productoCampana->fill($datos);
        $productoCampana->save();

        return $productoCampana->fresh();
    }
}