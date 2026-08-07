<?php

namespace Database\Seeders;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UsuariosPruebaSeeder extends Seeder
{
    /**
     * Usuarios de referencia (Paquete A):
     * - admin.general@footwearpoint.test  → password
     * - admin@calzadosramirez.test        → password123
     * - empleado@calzadosramirez.test     → password
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // ------------------------------------------------------------------
        // 1) Admin general (sin distribuidora → team_id = 0)
        // ------------------------------------------------------------------
        $registrar->setPermissionsTeamId(0);

        $rolAdminGeneral = Role::firstOrCreate([
            'name'       => 'admin_general',
            'guard_name' => 'web',
            'team_id'    => 0,
        ]);

        $adminGeneral = Usuario::updateOrCreate(
            ['email' => 'admin.general@footwearpoint.test'],
            [
                'nombre'            => 'Admin General',
                'password'          => Hash::make('password'),
                'telefono'          => null,
                'estado'            => 'activo',
                'email_verified_at' => now(),
            ]
        );

        // Quitar roles previos y asignar admin_general en team 0
        $adminGeneral->roles()->detach();
        $registrar->setPermissionsTeamId(0);
        $adminGeneral->assignRole($rolAdminGeneral);

        // ------------------------------------------------------------------
        // 2) Distribuidora demo (Calzados Ramírez)
        // ------------------------------------------------------------------
        $distribuidora = Distribuidora::firstOrCreate(
            ['slug' => 'calzados-ramirez'],
            [
                'nombre_comercial'    => 'Calzados Ramírez',
                'razon_social'        => 'Calzados Ramírez S.A. de C.V.',
                'rfc'                 => 'CRA010101AAA',
                'marketplace_visible' => true,
                'estado'              => 'activa',
                'fecha_solicitud'     => now(),
                'fecha_aprobacion'    => now(),
            ]
        );

        $registrar->setPermissionsTeamId($distribuidora->id);

        $rolAdminDist = Role::firstOrCreate([
            'name'       => 'admin_distribuidora',
            'guard_name' => 'web',
            'team_id'    => $distribuidora->id,
        ]);

        $rolEmpleado = Role::firstOrCreate([
            'name'       => 'empleado',
            'guard_name' => 'web',
            'team_id'    => $distribuidora->id,
        ]);

        // ------------------------------------------------------------------
        // 3) Admin distribuidora — password123
        // ------------------------------------------------------------------
        $adminDist = Usuario::updateOrCreate(
            ['email' => 'admin@calzadosramirez.test'],
            [
                'nombre'            => 'Ana Ramírez',
                'password'          => Hash::make('password123'),
                'telefono'          => '9631112233',
                'estado'            => 'activo',
                'email_verified_at' => now(),
            ]
        );

        DistribuidoraStaff::firstOrCreate(
            [
                'distribuidora_id' => $distribuidora->id,
                'usuario_id'       => $adminDist->id,
            ],
            [
                'tipo'       => 'administrador',
                'estado'     => 'activo',
                'fecha_alta' => now(),
            ]
        );

        $adminDist->roles()->detach();
        $registrar->setPermissionsTeamId($distribuidora->id);
        $adminDist->assignRole($rolAdminDist);

        // ------------------------------------------------------------------
        // 4) Empleado — password
        // ------------------------------------------------------------------
        $empleado = Usuario::updateOrCreate(
            ['email' => 'empleado@calzadosramirez.test'],
            [
                'nombre'            => 'Carlos Gómez',
                'password'          => Hash::make('password'),
                'telefono'          => '9634445566',
                'estado'            => 'activo',
                'email_verified_at' => now(),
            ]
        );

        DistribuidoraStaff::firstOrCreate(
            [
                'distribuidora_id' => $distribuidora->id,
                'usuario_id'       => $empleado->id,
            ],
            [
                'tipo'       => 'empleado',
                'estado'     => 'activo',
                'fecha_alta' => now(),
            ]
        );

        $empleado->roles()->detach();
        $registrar->setPermissionsTeamId($distribuidora->id);
        $empleado->assignRole($rolEmpleado);

        $registrar->forgetCachedPermissions();

        $this->command?->info('Usuarios de prueba listos:');
        $this->command?->info('  admin.general@footwearpoint.test  / password     → /admin');
        $this->command?->info('  admin@calzadosramirez.test        / password123  → /dashboard');
        $this->command?->info('  empleado@calzadosramirez.test     / password     → /dashboard');
    }
}