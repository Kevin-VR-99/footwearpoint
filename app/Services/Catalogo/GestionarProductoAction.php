<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Exceptions\OperacionInvalidaException;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private AuditarCambios $auditar,
        private DependenciasCatalogo $dependencias,
    )
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
        // El producto ya no tiene línea (D5): no hay nada que cruzar entre
        // marca y línea. Antes aquí se llamaba a un método que ya no existía
        // y cambiar la marca tronaba (TG-247).
        unset($datos['linea_id']);

        $producto->fill($datos);
        $this->auditar->guardar($producto, 'catalogo.producto.actualizado', 'producto');

        return $producto->fresh(['marca', 'categoria']);
    }

    /**
     * TG-247: borra el producto si ninguna distribuidora lo tiene en stock,
     * pedidos, ventas, precios propios o destacados. Sus variantes,
     * publicaciones en temporadas, disponibilidad e imágenes son del catálogo
     * y la base las borra con él. Nunca se toca nada de las distribuidoras.
     */
    public function eliminar(Producto $producto): void
    {
        $dependencias = $this->dependencias->deProducto($producto);

        if ($dependencias !== []) {
            throw new OperacionInvalidaException(
                'No se puede eliminar el producto: tiene ' . $this->dependencias->describir($dependencias)
                . '. Desactívalo en su lugar.'
            );
        }

        DB::transaction(function () use ($producto) {
            $this->auditar->eliminado($producto, 'catalogo.producto.eliminado', 'producto');
            $producto->delete();
        });
    }

}