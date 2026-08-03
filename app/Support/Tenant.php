<?php

namespace App\Support;

use App\Models\DistribuidoraStaff;
use Illuminate\Support\Facades\Auth;

class Tenant
{
    protected static ?int $overrideId = null;

    // Devuelve el id de la distribuidora del usuario que inició sesión,
    // buscando su membresía en distribuidora_staff. Si es admin_general
    // (no tiene fila en distribuidora_staff) o nadie inició sesión, regresa
    // null — y null significa "sin restricción" en el Global Scope.
    public static function id(): ?int
    {
        if (static::$overrideId !== null) {
            return static::$overrideId;
        }

        $usuario = Auth::user();

        if (! $usuario) {
            return null;
        }

        $staff = DistribuidoraStaff::where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->first();

        return $staff?->distribuidora_id;
    }

    // Para usar en Seeders o comandos de consola, donde no hay usuario
    // autenticado pero sí sabemos a qué distribuidora pertenece lo que se
    // está creando.
    public static function forzar(?int $distribuidoraId, callable $callback)
    {
        $anterior = static::$overrideId;
        static::$overrideId = $distribuidoraId;

        try {
            return $callback();
        } finally {
            static::$overrideId = $anterior;
        }
    }
}