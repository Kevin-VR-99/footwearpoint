<?php

namespace App\Support;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Tenant
{
    protected static ?int $overrideId = null;

    // Caché por petición: sin esto, CADA consulta Eloquent de la app
    // dispara una consulta extra a distribuidora_staff.
    protected static ?int $cacheUsuarioId = null;
    protected static ?int $cacheDistribuidoraId = null;

    // Mismo motivo que el caché de arriba: esAdminGeneral() lo consulta
    // TenantScope en cada query, así que no puede ir a la base cada vez.
    protected static ?int $cacheAdminUsuarioId = null;
    protected static bool $cacheEsAdminGeneral = false;

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

        // Se resuelve ANTES de marcar el caché: si se marcara primero, durante
        // la resolución el caché diría "ya tengo la respuesta de este usuario"
        // mientras todavía guarda la del usuario anterior.
        $distribuidoraId = static::paraUsuario((int) $usuario->id);

        static::$cacheUsuarioId = (int) $usuario->id;
        static::$cacheDistribuidoraId = $distribuidoraId;

        return static::$cacheDistribuidoraId;
    }

    /**
     * Resuelve la distribuidora de un usuario por sus tres caminos posibles,
     * en este orden de prioridad:
     *
     *   1. Empleado o administrador  -> distribuidora_staff
     *   2. Revendedor                -> revendedores + revendedor_distribuidora
     *   3. Cliente directo           -> clientes_directos
     *
     * El orden importa: si una misma persona fuera empleado Y cliente directo
     * de la distribuidora, opera como empleado (el rol de más alcance gana).
     *
     * Se expone como método público — y no solo dentro de id() — porque hay
     * puntos del sistema que necesitan resolver la distribuidora de un usuario
     * que todavía NO es el usuario autenticado (por ejemplo, el login).
     */
    public static function paraUsuario(int $usuarioId): ?int
    {
        return static::desdeStaff($usuarioId)
            ?? static::desdeRevendedor($usuarioId)
            ?? static::desdeClienteDirecto($usuarioId);
    }

    // ------------------------------------------------------------------
    // Resolutores por tipo de usuario
    //
    // REGLA INVIOLABLE para los tres: withoutGlobalScopes().
    // Estas son las consultas que RESUELVEN el tenant, así que no pueden
    // pasar por el TenantScope — se llamarían a sí mismas sin parar hasta
    // agotar la memoria. RevendedorDistribuidora y ClienteDirecto usan
    // BelongsToTenant, así que la trampa es real en ambas.
    // ------------------------------------------------------------------

    protected static function desdeStaff(int $usuarioId): ?int
    {
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->first();

        return $staff !== null ? (int) $staff->distribuidora_id : null;
    }

    protected static function desdeRevendedor(int $usuarioId): ?int
    {
        // La tabla revendedores es global (no tiene distribuidora_id): un
        // revendedor es una persona, y su relación con cada distribuidora
        // vive en revendedor_distribuidora. Por eso son dos pasos.
        $revendedor = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->first();

        if ($revendedor === null) {
            return null;
        }

        // El esquema permite que un mismo revendedor esté afiliado a varias
        // distribuidoras, pero Tenant::id() solo puede devolver una. Se toma
        // la primera por distribuidora_id — ordenada a propósito, para que la
        // respuesta sea siempre la misma y no dependa de lo que MySQL decida
        // devolver primero.
        //
        // Que ese caso doble no llegue a existir se resuelve en el alta de la
        // cuenta (E3-07 / TG-133), rechazando activar un correo que ya tiene
        // cuenta en otra distribuidora. Esto de aquí es solo la red de
        // seguridad por si aparecen datos viejos o metidos a mano.
        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->where('estado', 'activo')
            ->orderBy('distribuidora_id')
            ->first();

        return $afiliacion !== null ? (int) $afiliacion->distribuidora_id : null;
    }

    protected static function desdeClienteDirecto(int $usuarioId): ?int
    {
        // Mismo criterio de desempate que en desdeRevendedor.
        $cliente = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->orderBy('distribuidora_id')
            ->first();

        return $cliente !== null ? (int) $cliente->distribuidora_id : null;
    }

    /**
     * ¿El usuario autenticado es admin_general?
     *
     * admin_general administra todo el SaaS y a propósito NO pertenece a
     * ninguna distribuidora, así que su Tenant::id() es null de forma
     * legítima. TenantScope necesita distinguirlo de un usuario al que
     * simplemente no se le pudo resolver la distribuidora.
     *
     * Se consulta la tabla pivote directo, sin pasar por hasRole(), porque
     * hasRole() filtra por el "team" (la distribuidora) que esté fijado en
     * ese momento — y TenantScope corre en contextos donde ese team puede
     * no estar fijado todavía. Aquí el resultado no debe depender de eso.
     */
    public static function esAdminGeneral(): bool
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return false;
        }

        if (static::$cacheAdminUsuarioId === (int) $usuario->id) {
            return static::$cacheEsAdminGeneral;
        }

        $tablaRoles = config('permission.table_names.roles', 'roles');
        $tablaPivote = config('permission.table_names.model_has_roles', 'model_has_roles');

        $esAdminGeneral = DB::table($tablaPivote)
            ->join($tablaRoles, $tablaRoles . '.id', '=', $tablaPivote . '.role_id')
            ->where($tablaPivote . '.model_id', $usuario->id)
            ->where($tablaPivote . '.model_type', $usuario->getMorphClass())
            ->where($tablaRoles . '.name', 'admin_general')
            ->exists();

        static::$cacheAdminUsuarioId = (int) $usuario->id;
        static::$cacheEsAdminGeneral = $esAdminGeneral;

        return $esAdminGeneral;
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
        static::$cacheAdminUsuarioId = null;
        static::$cacheEsAdminGeneral = false;
    }
}
