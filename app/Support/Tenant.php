<?php

namespace App\Support;

use App\Models\DistribuidoraStaff;
use Illuminate\Support\Facades\Auth;

class Tenant
{
    protected static ?int $overrideId = null;

    // Caché por petición: sin esto, CADA consulta Eloquent de la app
    // dispara una consulta extra a distribuidora_staff.
    protected static ?int $cacheUsuarioId = null;
    protected static ?int $cacheDistribuidoraId = null;

    public static function id(): ?int
    {
        if (static::$overrideId !== null) {
            return static::$overrideId;
        }

        $usuario = Auth::user();

        if (! $usuario) {
            return null;
        }

        if (static::$cacheUsuarioId === (int) $usuario->id) {
            return static::$cacheDistribuidoraId;
        }

        // withoutGlobalScopes es OBLIGATORIO: esta es la consulta que resuelve
        // el tenant, así que no puede pasar por el TenantScope — se llamaría
        // a sí misma sin parar hasta agotar la memoria.
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->first();

        static::$cacheUsuarioId = (int) $usuario->id;
        static::$cacheDistribuidoraId = $staff !== null
            ? (int) $staff->distribuidora_id
            : null;

        return static::$cacheDistribuidoraId;
    }

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

    // Necesario en pruebas, donde varios usuarios inician sesión en el
    // mismo proceso.
    public static function olvidarCache(): void
    {
        static::$cacheUsuarioId = null;
        static::$cacheDistribuidoraId = null;
    }
}