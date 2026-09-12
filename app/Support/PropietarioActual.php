<?php

namespace App\Support;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Contesta "¿quién está pidiendo esto, dentro de su distribuidora?".
 *
 * Es el complemento de App\Support\Tenant: Tenant dice DE QUÉ DISTRIBUIDORA
 * es el usuario; esta clase dice QUIÉN ES dentro de ella, y por lo tanto qué
 * le toca ver.
 *
 * Hay tres respuestas posibles:
 *   - staff            -> admin o empleado. Ve todos los pedidos y vales de
 *                         su distribuidora, igual que siempre.
 *   - cliente_directo  -> solo lo suyo. Se devuelve el id de su ficha en
 *                         clientes_directos.
 *   - revendedor       -> solo lo suyo. Se devuelve el id de su fila en
 *                         revendedor_distribuidora (no el del revendedor:
 *                         es lo que guardan pedidos y vales).
 *
 * Y un cuarto caso: null, "no se pudo determinar". Ahí no se asume nada y no
 * se muestra nada — mismo criterio que TenantScope: no filtrar no es lo mismo
 * que no mostrar.
 */
class PropietarioActual
{
    public const STAFF = 'staff';
    public const CLIENTE_DIRECTO = 'cliente_directo';
    public const REVENDEDOR = 'revendedor';

    // Caché por petición: esto se consulta en cada endpoint de pedidos y
    // vales, mismo motivo que el caché de Tenant.
    protected static ?int $cacheUsuarioId = null;
    protected static ?array $cacheResultado = null;

    /**
     * ['tipo' => 'staff'] |
     * ['tipo' => 'cliente_directo', 'id' => int] |
     * ['tipo' => 'revendedor', 'id' => int] |
     * null si no se pudo determinar.
     */
    public static function actual(): ?array
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return null;
        }

        if (static::$cacheUsuarioId === (int) $usuario->id) {
            return static::$cacheResultado;
        }

        $resultado = static::resolver((int) $usuario->id);

        static::$cacheUsuarioId = (int) $usuario->id;
        static::$cacheResultado = $resultado;

        return $resultado;
    }

    public static function esDeLaCasa(): bool
    {
        $actual = static::actual();

        return $actual !== null && $actual['tipo'] === self::STAFF;
    }

    /**
     * Limita una consulta de pedidos o vales a lo que le corresponde a quien
     * pide. Ambas tablas guardan al dueño en las mismas dos columnas.
     */
    public static function limitar(Builder $consulta): Builder
    {
        $actual = static::actual();

        // No se pudo determinar quién pide: no se le muestra nada. Nunca
        // "no filtres", que sería mostrarle lo de todos.
        if ($actual === null) {
            return $consulta->whereRaw('1 = 0');
        }

        if ($actual['tipo'] === self::STAFF) {
            return $consulta;
        }

        if ($actual['tipo'] === self::CLIENTE_DIRECTO) {
            return $consulta->where('cliente_directo_id', $actual['id']);
        }

        return $consulta->where('revendedor_distribuidora_id', $actual['id']);
    }

    /**
     * withoutGlobalScopes en las tres consultas por la misma razón que en
     * Tenant: aquí todavía se está resolviendo quién es el usuario, así que
     * no puede pasar por los filtros que dependen de esa respuesta.
     */
    protected static function resolver(int $usuarioId): ?array
    {
        $distribuidoraId = Tenant::id();

        if ($distribuidoraId === null) {
            return null;
        }

        // El personal de la casa gana: si alguien fuera empleado Y cliente de
        // su propia distribuidora, opera como empleado.
        $esStaff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->exists();

        if ($esStaff) {
            return ['tipo' => self::STAFF];
        }

        $cliente = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activo')
            ->first();

        if ($cliente !== null) {
            return ['tipo' => self::CLIENTE_DIRECTO, 'id' => (int) $cliente->id];
        }

        $revendedor = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->first();

        if ($revendedor !== null) {
            $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
                ->where('revendedor_id', $revendedor->id)
                ->where('distribuidora_id', $distribuidoraId)
                ->where('estado', 'activo')
                ->first();

            if ($afiliacion !== null) {
                return ['tipo' => self::REVENDEDOR, 'id' => (int) $afiliacion->id];
            }
        }

        return null;
    }

    // Necesario en pruebas, donde varios usuarios inician sesión en el mismo
    // proceso.
    public static function olvidarCache(): void
    {
        static::$cacheUsuarioId = null;
        static::$cacheResultado = null;
    }
}
