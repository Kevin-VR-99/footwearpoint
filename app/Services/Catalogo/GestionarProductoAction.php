<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Models\Producto;

/**
 * Alta y edición de productos del catálogo compartido (TG-213).
 *
 * El producto ya no se amarra a una línea (D5): el mismo modelo puede salir en
 * los catálogos de dos líneas. La línea se sabe por la temporada donde se
 * publica.
 */
class GestionarProductoAction
{
    public function __construct(private AuditarCambios $auditar)
    {
    }

    public function crear(array $datos): Producto
    {
        $producto = Producto::create([
            'marca_id'     => $datos['marca_id'],
            'categoria_id' => $datos['categoria_id'],
            'modelo'       => $datos['modelo'],
            'nombre'       => $datos['nombre'],
            'descripcion'  => $datos['descripcion'] ?? null,
            'activo'       => true,
        ]);
        $this->auditar->creado($producto, 'catalogo.producto.creado', 'producto');

        return $producto;
    }

    public function actualizar(Producto $producto, array $datos): Producto
    {
        if (isset($datos['linea_id']) || isset($datos['marca_id'])) {
            $lineaId = (int) ($datos['linea_id'] ?? $producto->linea_id);
            $marcaId = (int) ($datos['marca_id'] ?? $producto->marca_id);
            $this->validarMarcaEnLinea($lineaId, $marcaId);
        }

        $producto->fill($datos);
        $this->auditar->guardar($producto, 'catalogo.producto.actualizado', 'producto');

        return $producto->fresh(['marca', 'categoria']);
    }

}