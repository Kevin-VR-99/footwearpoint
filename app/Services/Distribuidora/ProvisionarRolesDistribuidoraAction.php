<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\Usuario;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TG-194 (G2) — Los roles propios de una distribuidora.
 *
 * Los roles viven por distribuidora (team_id = distribuidora_id). Hasta ahora
 * solo la distribuidora demo los tenía (los crea su seeder): una distribuidora
 * dada de alta desde el panel se quedaba sin roles, así que no se le podía
 * asignar su administrador ni registrar su primer empleado.
 *
 * Es la única puerta para crearlos: la usan el alta y la aprobación (panel y
 * API). Se puede llamar las veces que sea: si ya existen, no duplica nada.
 */
class ProvisionarRolesDistribuidoraAction
{
    public const ROL_ADMINISTRADOR = 'admin_distribuidora';

    public const ROL_EMPLEADO = 'empleado';

    /** revendedor y cliente_directo también los crea ActivarCuentaAccesoAction cuando hacen falta. */
    public const ROLES = [
        self::ROL_ADMINISTRADOR,
        self::ROL_EMPLEADO,
        'revendedor',
        'cliente_directo',
    ];

    public function ejecutar(Distribuidora $distribuidora): void
    {
        $this->enEquipo($distribuidora, function () use ($distribuidora) {
            $roles = [];

            foreach (self::ROLES as $nombre) {
                $roles[$nombre] = $this->rol($nombre, $distribuidora);
            }

            // Los permisos son globales y normalmente ya los creó
            // RolesPermissionsSeeder; firstOrCreate por si no corrió.
            foreach (RolesPermissionsSeeder::PERMISOS_POR_MODULO as $permiso) {
                Permission::firstOrCreate([
                    'name'       => $permiso,
                    'guard_name' => 'web',
                ]);
            }

            // El admin de la distribuidora tiene todos los permisos por módulo.
            $roles[self::ROL_ADMINISTRADOR]->syncPermissions(RolesPermissionsSeeder::PERMISOS_POR_MODULO);

            $this->permisosEmpleado($roles[self::ROL_EMPLEADO]);
        });
    }

    /**
     * Le da a la persona el rol admin_distribuidora dentro de SU distribuidora.
     * Si ya lo tiene, no hace nada. Supone que ejecutar() ya corrió para que el
     * rol tenga sus permisos (si no existiera, se crea vacío).
     */
    public function asignarAdministrador(Usuario $usuario, Distribuidora $distribuidora): void
    {
        $this->enEquipo($distribuidora, function () use ($usuario, $distribuidora) {
            // Sin esto se reusarían los roles ya cargados de otro equipo.
            $usuario->unsetRelation('roles');

            if (! $usuario->hasRole(self::ROL_ADMINISTRADOR)) {
                $usuario->assignRole($this->rol(self::ROL_ADMINISTRADOR, $distribuidora));
            }

            $usuario->unsetRelation('roles');
        });
    }

    /**
     * Permisos por módulo del rol empleado.
     *
     * R4: aquí se le dan al empleado sus permisos por módulo (TG-208, R4). Hoy
     * no recibe ninguno a propósito: el empleado trabaja con su rol y el admin
     * le dará los permisos finos cuando R4 esté listo. Este método corre en
     * cada alta y aprobación, ya dentro del equipo de la distribuidora.
     */
    protected function permisosEmpleado(Role $empleado): void
    {
        //
    }

    private function rol(string $nombre, Distribuidora $distribuidora): Role
    {
        return Role::firstOrCreate([
            'name'       => $nombre,
            'guard_name' => 'web',
            'team_id'    => $distribuidora->id,
        ]);
    }

    /**
     * Corre $callback con el equipo de la distribuidora y al final regresa el
     * equipo que había: quien llama (el admin general, equipo 0) sigue
     * trabajando en el suyo.
     */
    private function enEquipo(Distribuidora $distribuidora, callable $callback): void
    {
        $registrar = app(PermissionRegistrar::class);
        $equipoAnterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($distribuidora->id);

            $callback();
        } finally {
            $registrar->setPermissionsTeamId($equipoAnterior);
            $registrar->forgetCachedPermissions();
        }
    }
}
