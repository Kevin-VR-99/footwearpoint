<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
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

        // admin_distribuidora y empleado NO se crean aquí: son roles que
        // se crean "por distribuidora" (con su propio team_id), justo
        // cuando esa distribuidora se aprueba. Ver DemoDistribuidoraSeeder
        // para el ejemplo con la distribuidora de prueba.

        $registrar->forgetCachedPermissions();
    }
}