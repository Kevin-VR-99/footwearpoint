<?php

namespace App\Support;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraStaff;
use App\Models\Sucursal;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve el contexto operativo (staff, distribuidora y sucursal principal)
 * SIEMPRE a partir del usuario autenticado, nunca de la peticion.
 * Convencion 1.8 del Plan de Tareas de Programacion del MVP.
 *
 * Complementa a App\Support\Tenant: Tenant da el id de la distribuidora;
 * esta clase da ademas la fila de staff (para registrado_por_staff_id) y
 * la sucursal principal.
 */
class ContextoOperativo
{
    private ?DistribuidoraStaff $staff = null;
    private ?Sucursal $sucursal = null;

    public function staff(): DistribuidoraStaff
    {
        if ($this->staff !== null) {
            return $this->staff;
        }

        $usuarioId = Auth::id();

        if ($usuarioId === null) {
            throw new OperacionInvalidaException('No hay un usuario autenticado.', 401);
        }

        // withoutGlobalScopes: esta es la consulta que resuelve el tenant,
        // por lo que no puede filtrarse a si misma por distribuidora_id.
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->orderBy('id')
            ->first();

        if ($staff === null) {
            throw new OperacionInvalidaException(
                'El usuario autenticado no es staff activo de ninguna distribuidora.',
                403
            );
        }

        return $this->staff = $staff;
    }

    public function distribuidoraId(): int
    {
        return (int) $this->staff()->distribuidora_id;
    }

    /**
     * El MVP opera con una sola sucursal (seccion 2 del Plan de Tareas).
     */
    public function sucursalPrincipal(): Sucursal
    {
        if ($this->sucursal !== null) {
            return $this->sucursal;
        }

        $sucursal = Sucursal::query()
            ->where('distribuidora_id', $this->distribuidoraId())
            ->where('es_principal', true)
            ->where('activa', true)
            ->first();

        if ($sucursal === null) {
            throw new OperacionInvalidaException(
                'La distribuidora no tiene una sucursal principal activa.',
                409
            );
        }

        return $this->sucursal = $sucursal;
    }
}