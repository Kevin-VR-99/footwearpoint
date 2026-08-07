<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Linea;
use App\Models\Producto;

class GestionarProductoAction
{
    public function crear(array $datos): Producto
    {
        $this->validarMarcaEnLinea((int) $datos['linea_id'], (int) $datos['marca_id']);

        return Producto::create([
            'marca_id'     => $datos['marca_id'],
            'linea_id'     => $datos['linea_id'],
            'categoria_id' => $datos['categoria_id'],
            'modelo'       => $datos['modelo'],
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'activo'       => true,
        ]);
    }

    public function actualizar(Producto $producto, array $datos): Producto
    {
        if (isset($datos['linea_id']) || isset($datos['marca_id'])) {
            $lineaId = (int) ($datos['linea_id'] ?? $producto->linea_id);
            $marcaId = (int) ($datos['marca_id'] ?? $producto->marca_id);
            $this->validarMarcaEnLinea($lineaId, $marcaId);
        }

        $producto->fill($datos);
        $producto->save();

        return $producto->fresh(['marca', 'linea', 'categoria']);
    }

    private function validarMarcaEnLinea(int $lineaId, int $marcaId): void
    {
        $linea = Linea::find($lineaId);

        if (! $linea) {
            throw new OperacionInvalidaException('La línea indicada no existe.', 422);
        }

        $ok = $linea->marcas()->where('marcas.id', $marcaId)->exists();

        if (! $ok) {
            throw new OperacionInvalidaException(
                'La marca no está asociada a esa línea. Asocia la marca a la línea antes de crear el producto.',
                422
            );
        }
    }
}