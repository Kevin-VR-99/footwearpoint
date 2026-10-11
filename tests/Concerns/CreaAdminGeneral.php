<?php

namespace Tests\Concerns;

use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El admin general para las pruebas (TG-213).
 *
 * Desde el Sprint 4 el catálogo lo escribe solo el admin general, así que
 * varias pruebas necesitan esa cuenta. Todavía no viene en los seeders de
 * demo (eso es K10), por eso se crea aquí.
 *
 * No pertenece a ninguna distribuidora: su rol vive en el "equipo" 0.
 */
trait CreaAdminGeneral
{
    protected function adminGeneral(): Usuario
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);

        $rol = Role::firstOrCreate([
            'name' => 'admin_general',
            'guard_name' => 'web',
            'team_id' => 0,
        ]);

        $usuario = Usuario::firstOrCreate(
            ['email' => 'admin.general@footwearpoint.test'],
            [
                'nombre' => 'Admin General',
                'password' => Hash::make('password'),
                'estado' => 'activo',
                'email_verified_at' => now(),
            ]
        );

        if (! $usuario->hasRole($rol)) {
            $usuario->assignRole($rol);
        }

        $registrar->forgetCachedPermissions();

        return $usuario;
    }
}
