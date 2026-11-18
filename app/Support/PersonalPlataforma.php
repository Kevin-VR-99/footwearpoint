<?php

namespace App\Support;

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarSesionAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as ConsultaBase;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * TG-335 (K37) — El personal de la plataforma, para el admin general.
 *
 * Personal es quien tiene rol de admin general, admin de distribuidora o
 * empleado. Los roles se leen directo de la tabla: hasRole() y la relación
 * roles() dependen del equipo de permisos fijado en la petición, y aquí hacen
 * falta los de todas las distribuidoras sin cambiarlo.
 */
final class PersonalPlataforma
{
    public const ADMIN_GENERAL = 'admin_general';

    public const ROLES_DE_DISTRIBUIDORA = ['admin_distribuidora', 'empleado'];

    public const ROLES = [
        self::ADMIN_GENERAL => 'Admin general',
        'admin_distribuidora' => 'Admin de distribuidora',
        'empleado' => 'Empleado',
    ];

    public const ESTADOS = [
        'activo' => 'Activo',
        'bloqueado' => 'Bloqueado',
        'inactivo' => 'Inactivo',
    ];

    /** Lista cerrada: la contraseña y el token nunca salen de la base. */
    private const COLUMNAS = ['id', 'nombre', 'email', 'telefono', 'foto_url', 'estado', 'email_verified_at', 'created_at'];

    public static function esAdminGeneral(Usuario $usuario): bool
    {
        return self::asignaciones()
            ->where('model_has_roles.model_id', $usuario->getKey())
            ->where('roles.name', self::ADMIN_GENERAL)
            ->exists();
    }

    /** El listado: rol y distribuidora se cumplen en la misma asignación. */
    public static function consulta(
        string $buscar = '',
        string $rol = '',
        string $estado = '',
        string $distribuidoraId = ''
    ): Builder {
        $asignaciones = self::asignaciones()
            ->select('model_has_roles.model_id')
            ->when(isset(self::ROLES[$rol]), fn (ConsultaBase $q) => $q->where('roles.name', $rol))
            ->when(ctype_digit($distribuidoraId), fn (ConsultaBase $q) => $q->where('model_has_roles.team_id', (int) $distribuidoraId));

        $buscar = trim($buscar);

        return Usuario::query()
            ->select(self::COLUMNAS)
            ->whereIn('id', $asignaciones)
            ->when(isset(self::ESTADOS[$estado]), fn (Builder $q) => $q->where('estado', $estado))
            ->when($buscar !== '', function (Builder $q) use ($buscar) {
                $patron = '%'.addcslashes($buscar, '%_\\').'%';

                $q->where(fn (Builder $o) => $o->where('nombre', 'like', $patron)
                    ->orWhere('email', 'like', $patron)
                    ->orWhere('telefono', 'like', $patron));
            })
            ->orderByRaw('nombre is null')
            ->orderBy('nombre')
            ->orderBy('id');
    }

    /**
     * Los roles de personal de varias cuentas, en una sola consulta.
     *
     * @param  list<int>  $usuarioIds
     * @return array<int, list<array{rol: string, etiqueta: string, distribuidora: ?string, activo: bool}>>
     */
    public static function rolesDe(array $usuarioIds): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        $filas = self::asignaciones()
            ->leftJoin('distribuidoras', 'distribuidoras.id', '=', 'model_has_roles.team_id')
            ->leftJoin('distribuidora_staff', fn (JoinClause $j) => $j
                ->on('distribuidora_staff.usuario_id', '=', 'model_has_roles.model_id')
                ->on('distribuidora_staff.distribuidora_id', '=', 'model_has_roles.team_id'))
            ->whereIn('model_has_roles.model_id', $usuarioIds)
            ->orderBy('model_has_roles.team_id')
            ->orderBy('roles.name')
            ->get([
                'model_has_roles.model_id',
                'roles.name',
                'distribuidoras.nombre_comercial',
                'distribuidoras.razon_social',
                'distribuidora_staff.estado as estado_staff',
            ]);

        $roles = [];

        foreach ($filas as $fila) {
            $roles[(int) $fila->model_id][] = [
                'rol' => $fila->name,
                'etiqueta' => self::ROLES[$fila->name],
                'distribuidora' => $fila->nombre_comercial ?: $fila->razon_social,
                // Dado de baja en esa distribuidora, aunque su cuenta siga activa.
                'activo' => $fila->estado_staff !== 'inactivo',
            ];
        }

        return $roles;
    }

    public static function ultimoInicio(int $usuarioId): ?Auditoria
    {
        return Auditoria::withoutGlobalScopes()
            ->select(['id', 'accion', 'created_at'])
            ->where('usuario_id', $usuarioId)
            ->whereIn('accion', RegistrarSesionAction::INICIOS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Lo que hizo la persona. Sin datos_previos ni datos_nuevos: los registros
     * anteriores a TG-334 pueden traer datos que hoy ya no se guardan.
     */
    public static function movimientos(int $usuarioId): Builder
    {
        return Auditoria::withoutGlobalScopes()
            ->select(['id', 'distribuidora_id', 'accion', 'entidad_tipo', 'entidad_id', 'created_at'])
            ->with('distribuidora:id,nombre_comercial,razon_social')
            ->where('usuario_id', $usuarioId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** Solo roles de personal, cada uno en el equipo que le corresponde. */
    private static function asignaciones(): ConsultaBase
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new Usuario)->getMorphClass())
            ->where(fn (ConsultaBase $q) => $q
                ->where(fn (ConsultaBase $g) => $g
                    ->where('roles.name', self::ADMIN_GENERAL)
                    ->where('model_has_roles.team_id', 0))
                ->orWhere(fn (ConsultaBase $d) => $d
                    ->whereIn('roles.name', self::ROLES_DE_DISTRIBUIDORA)
                    ->where('model_has_roles.team_id', '>', 0)));
    }
}
