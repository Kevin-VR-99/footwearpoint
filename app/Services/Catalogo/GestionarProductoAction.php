<?php

namespace App\Services\Catalogo;

use App\Models\Producto;

class GestionarProductoAction
{
    public function crear(array $datos): Producto
    {
        return Producto::create([
            'marca_id'     => $datos['marca_id'],
            'categoria_id' => $datos['categoria_id'],
            'modelo'       => $datos['modelo'],
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'activo'       => true,
        ]);
    }

    public function actualizar(Producto $producto, array $datos): Producto
    {
        // marca_id nunca se acepta aquí (el Form Request no lo permite en
        // edición) — decisión provisional mía, ver LEEME de este bloque.
        $producto->fill($datos);
        $producto->save();

        return $producto->fresh();
    }
}
