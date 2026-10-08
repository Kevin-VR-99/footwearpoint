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
 * Las distribuidoras de demostración, con su personal (TG-186, TG-217).
 *
 * Se arman con las MISMAS acciones que una distribuidora real (G2): preparar
 * sus datos iniciales (sucursal, configuración, ciclo y suscripción) y
 * provisionar sus roles. Así, si mañana cambia el alta de verdad, la demo
 * cambia con ella y no se queda contando una historia distinta.
 *
 * Son dos a propósito: el catálogo es uno solo para todas, y con dos se ve
 * que cada una vende las líneas que quiere y pone sus propios precios
 * (TG-217). La primera es la completa, con admin y empleado; la segunda solo
 * tiene su admin, que es lo que hace falta para entrar y comparar.
 */
class DemoDistribuidoraSeeder extends Seeder
{
    public const SLUG = 'calzados-ramirez';

    public const ADMIN = 'admin@calzadosramirez.test';

    public const EMPLEADO = 'empleado@calzadosramirez.test';

    public const SEGUNDA_SLUG = 'boutique-del-calzado';

    public const SEGUNDA_ADMIN = 'admin@boutiquedelcalzado.test';

    public function run(): void
    {
        $primera = $this->distribuidora([
            'slug' => self::SLUG,
            'nombre_comercial' => 'Calzados Ramírez',
            'razon_social' => 'Calzados Ramírez S.A. de C.V.',
            'rfc' => 'CRA010101AAA',
            'descripcion_publica' => 'Distribuidora multimarca de calzado, de prueba para el equipo.',
            'direccion_publica' => 'Av. Central 123, Comitán, Chiapas',
            'telefono_publico' => '9631234567',
            'email_publico' => 'contacto@calzadosramirez.test',
            'horario_publico' => 'Lunes a sábado, 9:00 a 19:00',
        ]);

        $admin = $this->cuenta(self::ADMIN, 'Ana Ramírez', '9631112233');
        $this->comoStaff($admin, $primera, 'administrador');
        app(ProvisionarRolesDistribuidoraAction::class)->asignarAdministrador($admin, $primera);

        $empleado = $this->cuenta(self::EMPLEADO, 'Carlos Gómez', '9634445566');
        $this->comoStaff($empleado, $primera, 'empleado');
        $this->comoEmpleado($empleado, $primera);

        $segunda = $this->distribuidora([
            'slug' => self::SEGUNDA_SLUG,
            'nombre_comercial' => 'Boutique del Calzado',
            'razon_social' => 'Boutique del Calzado S. de R.L.',
            'rfc' => 'BCA020202BBB',
            'descripcion_publica' => 'Calzado de dama y deportivo, de prueba para el equipo.',
            'direccion_publica' => 'Calle 5 de Mayo 45, Tuxtla Gutiérrez, Chiapas',
            'telefono_publico' => '9617778899',
            'email_publico' => 'contacto@boutiquedelcalzado.test',
            'horario_publico' => 'Lunes a sábado, 10:00 a 20:00',
            // No sale en el directorio público: está para ver el catálogo
            // compartido desde otra distribuidora, no para la demo del
            // directorio, que ya cuenta la primera (TG-217).
            'marketplace_visible' => false,
        ]);

        $adminSegunda = $this->cuenta(self::SEGUNDA_ADMIN, 'Laura Méndez', '9617771122');
        $this->comoStaff($adminSegunda, $segunda, 'administrador');
        app(ProvisionarRolesDistribuidoraAction::class)->asignarAdministrador($adminSegunda, $segunda);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Distribuidoras demo listas: {$primera->id} y {$segunda->id}.");
    }

    /** La distribuidora con sus datos iniciales, su suscripción y sus roles. */
    private function distribuidora(array $datos): Distribuidora
    {
        $distribuidora = Distribuidora::firstOrCreate(
            ['slug' => $datos['slug']],
            $datos + [
                'marketplace_visible' => true,
                'estado' => 'activa',
                'fecha_solicitud' => now(),
                'fecha_aprobacion' => now(),
            ]
        );

        $preparar = app(PrepararDistribuidoraAction::class);

        // Sucursal principal, configuración operativa y ciclo de compra.
        $preparar->datosIniciales($distribuidora);

        if ($distribuidora->suscripciones()->where('estado', 'activa')->doesntExist()) {
            $preparar->suscripcionInicial($distribuidora, $preparar->planPorDefecto());
        }

        // Sus roles propios, incluidos los de la app (revendedor y cliente).
        app(ProvisionarRolesDistribuidoraAction::class)->ejecutar($distribuidora);

        return $distribuidora;
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
