<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Database\Seeders\Support\PasswordDePrueba;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El admin general, que es quien administra FootwearPoint completo (TG-186).
 *
 * No pertenece a ninguna distribuidora: su rol vive en el "equipo" 0. Desde el
 * Sprint 4 también es el único que administra el catálogo compartido, así que
 * sin esta cuenta la demo queda a medias.
 */
class AdminGeneralSeeder extends Seeder
{
    public const EMAIL = 'admin.general@footwearpoint.test';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);

        $rol = Role::firstOrCreate([
            'name' => 'admin_general',
            'guard_name' => 'web',
            'team_id' => 0,
        ]);

        $usuario = Usuario::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'nombre' => 'Admin General',
                'password' => Hash::make(PasswordDePrueba::obtener()),
                'estado' => 'activo',
                'email_verified_at' => now(),
            ]
        );

        if (! $usuario->hasRole($rol)) {
            $usuario->assignRole($rol);
        }

        $registrar->forgetCachedPermissions();
    }
}
