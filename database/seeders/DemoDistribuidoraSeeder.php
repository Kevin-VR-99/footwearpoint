<?php

namespace Database\Seeders;

use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionCicloDiaRecepcion;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoDistribuidoraSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // --- 1. La distribuidora ---
        $distribuidora = Distribuidora::firstOrCreate(
            ['slug' => 'calzados-ramirez'],
            [
                'nombre_comercial' => 'Calzados Ramírez',
                'razon_social' => 'Calzados Ramírez S.A. de C.V.',
                'rfc' => 'CRA010101AAA',
                'subdominio' => null,
                'logotipo_url' => null,
                'descripcion_publica' => 'Distribuidora multimarca de calzado, de prueba para el equipo.',
                'direccion_publica' => 'Av. Central 123, Comitán, Chiapas',
                'telefono_publico' => '9631234567',
                'email_publico' => 'contacto@calzadosramirez.test',
                'horario_publico' => 'Lunes a sábado, 9:00 a 19:00',
                'marketplace_visible' => true,
                'estado' => 'activa',
                'fecha_solicitud' => now(),
                'fecha_aprobacion' => now(),
            ]
        );

        // --- 2. Su sucursal principal ---
        Sucursal::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'nombre' => 'Sucursal Principal'],
            [
                'direccion' => 'Av. Central 123, Comitán, Chiapas',
                'telefono' => '9631234567',
                'es_principal' => true,
                'activa' => true,
            ]
        );

        // --- 3. Su configuración operativa (valores por defecto) ---
        ConfiguracionDistribuidora::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id],
            [
                'anticipo_por_producto' => 100.00,
                'dias_solicitud_cambio' => 12,
                'dias_gestion_devolucion' => 20,
                'dias_vigencia_vale' => 90,
                'dias_maximos_recoleccion' => 5,
                'moneda' => 'MXN',
                'zona_horaria' => 'America/Mexico_City',
            ]
        );

        // --- 4. Configuración de su ciclo de compra ---
        // Nota: se usa la convención 1=lunes ... 7=domingo. Recepción
        // miércoles a viernes, cierre viernes, solicitud a fábrica viernes,
        // llegada estimada 5 días después (igual que el ejemplo del Plan
        // de Trabajo).
        $configCiclo = ConfiguracionCiclo::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id],
            [
                'dia_cierre' => 5,
                'hora_cierre' => '18:00:00',
                'dia_solicitud_fabrica' => 5,
                'dias_estimados_llegada' => 5,
                'activa' => true,
            ]
        );

        foreach ([3, 4, 5] as $diaSemana) {
            ConfiguracionCicloDiaRecepcion::firstOrCreate([
                'configuracion_ciclo_id' => $configCiclo->id,
                'dia_semana' => $diaSemana,
            ]);
        }

        // --- 5. Sus roles propios (scopeados a esta distribuidora) ---
        $registrar->setPermissionsTeamId($distribuidora->id);

        $rolAdmin = Role::firstOrCreate([
            'name' => 'admin_distribuidora',
            'guard_name' => 'web',
            'team_id' => $distribuidora->id,
        ]);

        $rolEmpleado = Role::firstOrCreate([
            'name' => 'empleado',
            'guard_name' => 'web',
            'team_id' => $distribuidora->id,
        ]);

        // --- 6. Sus 2 empleados de prueba ---
        // Contraseña de prueba para ambos: "password"
        $admin = Usuario::firstOrCreate(
            ['email' => 'admin@calzadosramirez.test'],
            [
                'nombre' => 'Ana Ramírez',
                'password' => Hash::make('password'),
                'telefono' => '9631112233',
                'estado' => 'activo',
                'email_verified_at' => now(),
            ]
        );
        DistribuidoraStaff::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'usuario_id' => $admin->id],
            ['tipo' => 'administrador', 'estado' => 'activo', 'fecha_alta' => now()]
        );
        $admin->assignRole($rolAdmin);

        $empleado = Usuario::firstOrCreate(
            ['email' => 'empleado@calzadosramirez.test'],
            [
                'nombre' => 'Carlos Gómez',
                'password' => Hash::make('password'),
                'telefono' => '9634445566',
                'estado' => 'activo',
                'email_verified_at' => now(),
            ]
        );
        DistribuidoraStaff::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'usuario_id' => $empleado->id],
            ['tipo' => 'empleado', 'estado' => 'activo', 'fecha_alta' => now()]
        );
        $empleado->assignRole($rolEmpleado);

        $registrar->forgetCachedPermissions();

        $this->command->info("Distribuidora demo creada con id: {$distribuidora->id}");
    }
}