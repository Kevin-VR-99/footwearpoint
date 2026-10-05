<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    /**
     * Permisos por módulo (R4, TG-208).
     *
     * Son los permisos finos que viven ADENTRO de una distribuidora: el
     * admin_distribuidora los tiene todos y al empleado se le dan uno por uno.
     *
     * Aquí solo se crean los permisos. Dárselos a cada rol se hace donde se
     * crean los roles de cada distribuidora, que es trabajo de R4 y G2.
     */
    public const PERMISOS_POR_MODULO = [
        'ciclo.cerrar',
        'ciclo.solicitar_fabrica',
        'reportes.ver_financieros',
        'configuracion.administrar',
        'usuarios.administrar',
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // admin_general es global: no pertenece a ninguna distribuidora,
        // por eso se crea sin equipo (team_id = null).
        $registrar->setPermissionsTeamId(0);

        Role::firstOrCreate([
            'name' => 'admin_general',
            'guard_name' => 'web',
        ]);

        // Los permisos son globales (la tabla permissions no lleva equipo):
        // se crean una sola vez y cada distribuidora se los asigna a SUS roles.
        foreach (self::PERMISOS_POR_MODULO as $permiso) {
            Permission::firstOrCreate([
                'name' => $permiso,
                'guard_name' => 'web',
            ]);
        }

        // admin_distribuidora y empleado NO se crean aquí: son roles que
        // se crean "por distribuidora" (con su propio team_id), justo
        // cuando esa distribuidora se aprueba. Ver DemoDistribuidoraSeeder
        // para el ejemplo con la distribuidora de prueba.

        $registrar->forgetCachedPermissions();
    }
}