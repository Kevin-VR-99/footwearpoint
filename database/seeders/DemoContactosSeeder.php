<?php

namespace Database\Seeders;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use Illuminate\Database\Seeder;

class DemoContactosSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        // --- 2 revendedores de prueba ---
        // Se crean como datos de contacto; más abajo solo María recibe cuenta
        // para la app (E3-07). Roberto se queda sin cuenta a propósito.
        $revendedoresDemo = [
            ['nombre' => 'María López', 'telefono' => '9635551111', 'email' => 'maria.lopez@revendedor.test'],
            ['nombre' => 'Roberto García', 'telefono' => '9635552222', 'email' => 'roberto.garcia@revendedor.test'],
        ];

        foreach ($revendedoresDemo as $i => $datos) {
            $revendedor = Revendedor::firstOrCreate(
                ['email' => $datos['email']],
                [
                    'nombre' => $datos['nombre'],
                    'telefono' => $datos['telefono'],
                    'estado' => 'activo',
                ]
            );

            RevendedorDistribuidora::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'revendedor_id' => $revendedor->id],
                [
                    'codigo_interno' => 'REV-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                    'estado' => 'activo',
                    'fecha_alta' => now(),
                ]
            );
        }

        // --- 3 clientes directos de prueba ---
        // Solo José recibe cuenta para la app (más abajo). Ana y Laura se quedan
        // sin cuenta: el empleado sigue capturando por ellas, y sirven para
        // probar la activación desde el panel. Las pruebas automáticas usan a
        // Ana como "cliente sin cuenta", así que no hay que dársela aquí.
        $clientesDemo = [
            ['nombre' => 'Ana García', 'telefono' => '9635553333', 'email' => 'ana.garcia@cliente.test'],
            ['nombre' => 'José Hernández', 'telefono' => '9635554444', 'email' => 'jose.hernandez@cliente.test'],
            ['nombre' => 'Laura Pérez', 'telefono' => '9635555555', 'email' => 'laura.perez@cliente.test'],
        ];

        foreach ($clientesDemo as $datos) {
            ClienteDirecto::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'email' => $datos['email']],
                [
                    'nombre' => $datos['nombre'],
                    'telefono' => $datos['telefono'],
                    'estado' => 'activo',
                ]
            );
        }

        // --- Cuentas para probar la app móvil (E3-07 / TG-133) ---
        // Contraseña de prueba para las dos: "password", igual que los
        // empleados demo. Se usa la misma acción que el panel y la API.
        $activar = app(ActivarCuentaAccesoAction::class);

        $afiliacionMaria = RevendedorDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->whereHas('revendedor', fn ($q) => $q->where('email', 'maria.lopez@revendedor.test'))
            ->with('revendedor')
            ->firstOrFail();

        if ($this->puedeRecibirCuenta($afiliacionMaria->revendedor->usuario_id, 'maria.lopez@revendedor.test')) {
            $activar->paraRevendedor($afiliacionMaria, 'maria.lopez@revendedor.test', 'password');
        }

        $jose = ClienteDirecto::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('email', 'jose.hernandez@cliente.test')
            ->firstOrFail();

        if ($this->puedeRecibirCuenta($jose->usuario_id, 'jose.hernandez@cliente.test')) {
            $activar->paraClienteDirecto($jose, 'jose.hernandez@cliente.test', 'password');
        }

        $this->command->info('Contactos demo creados: 2 revendedores afiliados y 3 clientes directos.');
        $this->command->info('Cuentas de la app: maria.lopez@revendedor.test y jose.hernandez@cliente.test (contraseña: password).');
    }

    /**
     * Para poder correr el seeder más de una vez sin que truene: si ya tiene
     * cuenta, o el correo ya existe, se salta.
     */
    private function puedeRecibirCuenta(?int $usuarioId, string $email): bool
    {
        return $usuarioId === null && ! Usuario::where('email', $email)->exists();
    }
}