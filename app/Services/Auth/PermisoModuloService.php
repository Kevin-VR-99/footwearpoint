<?php

namespace App\Services\Auth;

use App\Exceptions\SinPermisoModuloException;
use App\Models\Usuario;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Support\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * TG-246 — Permisos por módulo del personal (R4, TG-208).
 *
 * El admin de la distribuidora puede todo; al empleado se le dan uno por uno
 * (RolesPermissionsSeeder::PERMISOS_POR_MODULO). Antes solo el menú lo
 * respetaba: por URL, o con el botón de la pantalla, el empleado sin permiso
 * hacía lo mismo. Esta es la regla del servidor, en un solo lugar, para que
 * el menú, las pantallas y las acciones digan siempre lo mismo.
 */
class PermisoModuloService
{
    public const CERRAR_CICLO = 'ciclo.cerrar';
    public const SOLICITAR_FABRICA = 'ciclo.solicitar_fabrica';
    public const REPORTES_FINANCIEROS = 'reportes.ver_financieros';
    public const CONFIGURACION = 'configuracion.administrar';
    public const USUARIOS = 'usuarios.administrar';

    public function puede(?Usuario $usuario, string $permiso): bool
    {
        $distribuidoraId = Tenant::id();

        if ($usuario === null || $distribuidoraId === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $equipoAnterior = $registrar->getPermissionsTeamId();

        try {
            // Los roles y permisos viven en el equipo de la distribuidora. Se
            // fija aquí porque en las peticiones de Livewire no siempre pasa
            // el middleware que lo hace.
            $registrar->setPermissionsTeamId($distribuidoraId);
            $usuario->unsetRelation('roles')->unsetRelation('permissions');

            return $usuario->hasRole(ProvisionarRolesDistribuidoraAction::ROL_ADMINISTRADOR)
                || $usuario->hasPermissionTo($permiso);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        } finally {
            $registrar->setPermissionsTeamId($equipoAnterior);
            $usuario->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @throws SinPermisoModuloException */
    public function exigir(?Usuario $usuario, string $permiso): void
    {
        if (! $this->puede($usuario, $permiso)) {
            throw new SinPermisoModuloException();
        }
    }
}
