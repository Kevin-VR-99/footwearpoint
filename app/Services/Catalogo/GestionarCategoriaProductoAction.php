<?php

namespace App\Services\Catalogo;

use App\Services\Auditoria\AuditarCambios;
use App\Models\CategoriaProducto;

class GestionarCategoriaProductoAction
{
    public function __construct(private AuditarCambios $auditar)
    {
    }

    public function crear(array $datos): CategoriaProducto
    {
        $categoria = CategoriaProducto::create([
            'nombre'      => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activa'      => true,
        ]);
        $this->auditar->creado($categoria, 'catalogo.categoria.creada', 'categoria_producto');

        return $categoria;
    }

    public function actualizar(CategoriaProducto $categoria, array $datos): CategoriaProducto
    {
        $categoria->fill($datos);
        $this->auditar->guardar($categoria, 'catalogo.categoria.actualizada', 'categoria_producto');

        return $categoria->fresh();
    }
}
