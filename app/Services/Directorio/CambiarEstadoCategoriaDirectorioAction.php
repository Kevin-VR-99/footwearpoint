<?php

namespace App\Services\Directorio;

use App\Models\CategoriaDirectorio;
use App\Services\Auditoria\AuditarCambios;

/**
 * TG-197 (G16) — Activar o desactivar una categoría del directorio.
 *
 * Las categorías no se borran. Una inactiva deja de verse en el marketplace
 * y de ofrecerse al asignar, pero conserva sus distribuidoras: al activarla
 * de nuevo todo vuelve como estaba.
 */
class CambiarEstadoCategoriaDirectorioAction
{
    public function ejecutar(CategoriaDirectorio $categoria, bool $activa): CategoriaDirectorio
    {
        // TG-334
        $categoria->activa = $activa;
        app(AuditarCambios::class)->guardar($categoria, $activa ? 'categoria_directorio.activada' : 'categoria_directorio.desactivada', 'categoria_directorio');

        return $categoria->fresh();
    }
}
