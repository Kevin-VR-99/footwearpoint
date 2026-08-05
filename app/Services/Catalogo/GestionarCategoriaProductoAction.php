<?php

namespace App\Services\Catalogo;

use App\Models\CategoriaProducto;

class GestionarCategoriaProductoAction
{
    public function crear(array $datos): CategoriaProducto
    {
        return CategoriaProducto::create([
            'nombre'      => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activa'      => true,
        ]);
    }

    public function actualizar(CategoriaProducto $categoria, array $datos): CategoriaProducto
    {
        $categoria->fill($datos);
        $categoria->save();

        return $categoria->fresh();
    }
}
