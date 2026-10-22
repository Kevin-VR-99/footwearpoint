<?php

namespace App\Models\Concerns;

use App\Services\Distribuidora\RevocarAccesoUsuarioAction;

/**
 * Cuando el estado de un acceso (cliente mayorista, cliente directo o
 * empleado) pasa a 'inactivo' (baja) por CUALQUIER camino (botón de baja,
 * formulario de edición, API), sus tokens de la app se revocan.
 */
trait RevocaAccesoAlDesactivar
{
    public static function bootRevocaAccesoAlDesactivar(): void
    {
        static::updated(function ($modelo) {
            // Solo la baja ('inactivo'): 'suspendido' conserva la sesión (TG-140).
            // Y solo si ya no le queda otro acceso vigente (p. ej. en otra distribuidora).
            $usuarioId = $modelo->usuarioIdParaAcceso();
            if ($usuarioId && $modelo->wasChanged('estado') && $modelo->estado === 'inactivo'
                && \App\Http\Controllers\Api\AuthController::accesoDadoDeBaja($usuarioId)) {
                app(RevocarAccesoUsuarioAction::class)->ejecutar($usuarioId);
            }
        });
    }

    abstract public function usuarioIdParaAcceso(): ?int;
}
