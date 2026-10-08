<?php

namespace Database\Seeders;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Distribuidora\PrepararDistribuidoraAction;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use Database\Seeders\Support\PasswordDePrueba;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * La distribuidora de demostración, con su admin y su empleado (TG-186).
 *
 * Se arma con las MISMAS acciones que una distribuidora real (G2): preparar
 * sus datos iniciales (sucursal, configuración, ciclo y suscripción) y
 * provisionar sus roles. Así, si mañana cambia el alta de verdad, la demo
 * cambia con ella y no se queda contando una historia distinta.
 */
class DemoDistribuidoraSeeder extends Seeder
{
    public const SLUG = 'calzados-ramirez';

    public const ADMIN = 'admin@calzadosramirez.test';

    public const EMPLEADO = 'empleado@calzadosramirez.test';

    public function run(): void
    {
        $distribuidora = Distribuidora::firstOrCreate(
            ['slug' => self::SLUG],
            [
                'nombre_comercial' => 'Calzados Ramírez',
                'razon_social' => 'Calzados Ramírez S.A. de C.V.',
                'rfc' => 'CRA010101AAA',
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

        $preparar = app(PrepararDistribuidoraAction::class);
        $roles = app(ProvisionarRolesDistribuidoraAction::class);

        // Sucursal principal, configuración operativa y ciclo de compra.
        $preparar->datosIniciales($distribuidora);

        if ($distribuidora->suscripciones()->where('estado', 'activa')->doesntExist()) {
            $preparar->suscripcionInicial($distribuidora, $preparar->planPorDefecto());
        }

        // Sus roles propios, incluidos los de la app (revendedor y cliente).
        $roles->ejecutar($distribuidora);

        $admin = $this->cuenta(self::ADMIN, 'Ana Ramírez', '9631112233');
        $this->comoStaff($admin, $distribuidora, 'administrador');
        $roles->asignarAdministrador($admin, $distribuidora);

        $empleado = $this->cuenta(self::EMPLEADO, 'Carlos Gómez', '9634445566');
        $this->comoStaff($empleado, $distribuidora, 'empleado');
        $this->comoEmpleado($empleado, $distribuidora);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Distribuidora demo creada con id: {$distribuidora->id}");
    }

    /** El personal sí guarda su nombre y teléfono en la cuenta (TG-216). */
    private function cuenta(string $email, string $nombre, string $telefono): Usuario
    {
        return Usuario::firstOrCreate(
            ['email' => $email],
            [
                'nombre' => $nombre,
                'password' => Hash::make(PasswordDePrueba::obtener()),
                'telefono' => $telefono,
                'estado' => 'activo',
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * El rol de empleado se asigna dentro del "equipo" de la distribuidora: es
     * el mismo criterio que usa ProvisionarRolesDistribuidoraAction para el
     * administrador. Los roles ya los creó esa acción.
     */
    private function comoEmpleado(Usuario $usuario, Distribuidora $distribuidora): void
    {
        $registrar = app(PermissionRegistrar::class);
        $equipoAnterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($distribuidora->id);
            $usuario->unsetRelation('roles');

            if (! $usuario->hasRole(ProvisionarRolesDistribuidoraAction::ROL_EMPLEADO)) {
                $usuario->assignRole(ProvisionarRolesDistribuidoraAction::ROL_EMPLEADO);
            }
        } finally {
            $registrar->setPermissionsTeamId($equipoAnterior);
        }
    }

    private function comoStaff(Usuario $usuario, Distribuidora $distribuidora, string $tipo): void
    {
        DistribuidoraStaff::withoutGlobalScopes()->firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'usuario_id' => $usuario->id],
            ['tipo' => $tipo, 'estado' => 'activo', 'fecha_alta' => now()]
        );
    }
}
