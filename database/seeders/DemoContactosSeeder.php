<?php

namespace Database\Seeders;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use Database\Seeders\Support\DemoEnProduccion;
use Database\Seeders\Support\PasswordDePrueba;
use Illuminate\Database\Seeder;

class DemoContactosSeeder extends Seeder
{
    /** Los dos contactos que además tienen cuenta en la app (TG-186). */
    public const REVENDEDOR = 'maria.lopez@revendedor.test';

    public const CLIENTE_DIRECTO = 'jose.hernandez@cliente.test';

    public function run(): void
    {
        if (DemoEnProduccion::bloqueada($this, $this->command)) {
            return;
        }

        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        // --- 2 revendedores de prueba ---
        // Se crean como datos de contacto; más abajo solo María recibe cuenta
        // para la app (E3-07). Roberto se queda sin cuenta a propósito.
        $revendedoresDemo = [
            ['nombre' => 'María López', 'telefono' => '9635551111', 'email' => self::REVENDEDOR],
            ['nombre' => 'Roberto García', 'telefono' => '9635552222', 'email' => 'roberto.garcia@revendedor.test'],
        ];

        foreach ($revendedoresDemo as $i => $datos) {
            $revendedor = $this->revendedorDemo($datos['email']) ?? Revendedor::create([
                'email' => $datos['email'],
                'nombre' => $datos['nombre'],
                'telefono' => $datos['telefono'],
                'estado' => 'activo',
            ]);

            RevendedorDistribuidora::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'revendedor_id' => $revendedor->id],
                [
                    'codigo_interno' => 'REV-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT),
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
            ['nombre' => 'José Hernández', 'telefono' => '9635554444', 'email' => self::CLIENTE_DIRECTO],
            ['nombre' => 'Laura Pérez', 'telefono' => '9635555555', 'email' => 'laura.perez@cliente.test'],
        ];

        foreach ($clientesDemo as $datos) {
            if ($this->clienteDemo((int) $distribuidora->id, $datos['email']) !== null) {
                continue;
            }

            ClienteDirecto::create([
                'distribuidora_id' => $distribuidora->id,
                'email' => $datos['email'],
                'nombre' => $datos['nombre'],
                'telefono' => $datos['telefono'],
                'estado' => 'activo',
            ]);
        }

        // --- Cuentas para probar la app móvil (E3-07 / TG-133) ---
        // La misma contraseña de prueba que el resto de las cuentas (TG-186);
        // antes estaba escrita a mano aquí. Igual que los
        // empleados demo. Se usa la misma acción que el panel y la API.
        $activar = app(ActivarCuentaAccesoAction::class);

        // TG-278: se busca por contacto O por cuenta, porque al activarla el
        // correo del contacto queda vacío (ver revendedorDemo).
        $maria = $this->revendedorDemo(self::REVENDEDOR);

        $afiliacionMaria = $maria === null ? null : RevendedorDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('revendedor_id', $maria->id)
            ->with('revendedor')
            ->firstOrFail();

        if ($afiliacionMaria !== null
            && $this->puedeRecibirCuenta($afiliacionMaria->revendedor->usuario_id, self::REVENDEDOR)) {
            $activar->paraRevendedor($afiliacionMaria, self::REVENDEDOR, PasswordDePrueba::obtener());
        }

        $jose = $this->clienteDemo((int) $distribuidora->id, self::CLIENTE_DIRECTO);

        if ($jose !== null && $this->puedeRecibirCuenta($jose->usuario_id, self::CLIENTE_DIRECTO)) {
            $activar->paraClienteDirecto($jose, self::CLIENTE_DIRECTO, PasswordDePrueba::obtener());
        }

        $this->confirmarCorreoDeLasCuentasDemo();

        $this->command->info('Contactos demo creados: 2 clientes mayoristas afiliados y 3 clientes directos.');
        $this->command->info('Cuentas de la app: maria.lopez@revendedor.test y jose.hernandez@cliente.test.');
    }

    /**
     * Para poder correr el seeder más de una vez sin que truene: si ya tiene
     * cuenta, o el correo ya existe, se salta.
     */
    private function puedeRecibirCuenta(?int $usuarioId, string $email): bool
    {
        return $usuarioId === null && ! Usuario::where('email', $email)->exists();
    }

    /**
     * TG-278 — Buscar un contacto demo por su correo, con cuenta o sin ella.
     *
     * Al activarle la cuenta, ActivarCuentaAccesoAction vacía el correo del
     * contacto y lo deja solo en la cuenta (D4). Buscando únicamente por
     * contacto.email, volver a correr el seeder no encontraba a María ni a
     * José y les creaba un contacto duplicado. Por eso se busca en los dos
     * lados.
     */
    private function revendedorDemo(string $email): ?Revendedor
    {
        return Revendedor::withoutGlobalScopes()
            ->where(fn ($q) => $q
                ->where('email', $email)
                ->orWhereHas('usuario', fn ($u) => $u->where('email', $email)))
            ->first();
    }

    private function clienteDemo(int $distribuidoraId, string $email): ?ClienteDirecto
    {
        return ClienteDirecto::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where(fn ($q) => $q
                ->where('email', $email)
                ->orWhereHas('usuario', fn ($u) => $u->where('email', $email)))
            ->first();
    }

    /**
     * TG-278 — Las dos cuentas de la demostración nacen con el correo ya
     * confirmado.
     *
     * Sus correos son inventados (.test), así que nadie puede abrir el enlace
     * de confirmación. Cuando se encienda AUTH_VERIFICACION_OBLIGATORIA, el
     * middleware CorreoVerificado les bloquearía enviar pedidos y pagar
     * (TG-189), y la demostración de la app se caería.
     *
     * Va aparte de ActivarCuentaAccesoAction a propósito: una cuenta de
     * verdad sí tiene que confirmar su correo. Esto es solo para los datos de
     * prueba.
     *
     * Se hace también cuando la cuenta ya existía, para que volver a correr
     * el seeder arregle una base que ya estaba sembrada.
     */
    private function confirmarCorreoDeLasCuentasDemo(): void
    {
        $cuentas = Usuario::whereIn('email', [self::REVENDEDOR, self::CLIENTE_DIRECTO])->get();

        foreach ($cuentas as $cuenta) {
            if (! $cuenta->hasVerifiedEmail()) {
                $cuenta->markEmailAsVerified();
            }
        }
    }
}
