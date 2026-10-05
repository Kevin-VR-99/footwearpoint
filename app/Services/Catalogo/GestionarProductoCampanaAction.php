<?php

namespace App\Services\Catalogo;

use App\Models\Campana;
use App\Models\Producto;
use App\Models\ProductoCampana;

class GestionarProductoCampanaAction
{
    public function crear(array $datos): ProductoCampana
    {
        // Las dos tienen que existir; cualquier producto puede publicarse en
        // cualquier temporada (D5): es la temporada la que le da su línea.
        Producto::findOrFail($datos['producto_id']);
        Campana::findOrFail($datos['campana_id']);

        return ProductoCampana::create([
            'producto_id'               => $datos['producto_id'],
            'campana_id'                => $datos['campana_id'],
            'codigo_catalogo'           => $datos['codigo_catalogo'],
            'precio_publico'            => $datos['precio_publico'],
            'activo'                    => $datos['activo'] ?? true,
        ]);
    }

    public function actualizar(ProductoCampana $productoCampana, array $datos): ProductoCampana
    {
        $productoCampana->fill($datos);
        $productoCampana->save();

        return $productoCampana->fresh();
    }
}