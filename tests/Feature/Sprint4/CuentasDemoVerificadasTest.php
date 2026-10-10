<?php

namespace Tests\Feature\Sprint4;

use App\Http\Middleware\CorreoVerificado;
use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoContactosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-278 — Las dos cuentas de la demostración de la app nacen con el correo
 * confirmado.
 *
 * Sus correos son inventados (.test), así que nadie puede abrir el enlace de
 * confirmación. Sin esto, al encender AUTH_VERIFICACION_OBLIGATORIA el
 * middleware CorreoVerificado (TG-189) les bloquearía enviar pedidos y pagar,
 * y la demostración de la app se caería.
 */
class CuentasDemoVerificadasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function cuenta(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    public function test_las_dos_cuentas_de_la_app_quedan_con_el_correo_confirmado(): void
    {
        foreach ([DemoContactosSeeder::REVENDEDOR, DemoContactosSeeder::CLIENTE_DIRECTO] as $email) {
            $cuenta = $this->cuenta($email);

            $this->assertTrue(
                $cuenta->hasVerifiedEmail(),
                "La cuenta demo {$email} quedó sin confirmar."
            );
            $this->assertNotNull($cuenta->email_verified_at);
        }
    }

    /** Las cuentas de verdad siguen naciendo sin confirmar: esto es solo para los datos de prueba. */
    public function test_los_contactos_sin_cuenta_de_app_no_se_tocan(): void
    {
        // Roberto, Ana y Laura se quedan sin cuenta a propósito (TG-186), así
        // que aquí no hay nada que confirmar.
        $this->assertNull(Revendedor::where('email', 'roberto.garcia@revendedor.test')->firstOrFail()->usuario_id);
        $this->assertNull(ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail()->usuario_id);
    }

    public function test_correr_el_seeder_otra_vez_no_falla_ni_duplica_nada(): void
    {
        $antes = [
            'usuarios' => Usuario::count(),
            'revendedores' => Revendedor::withoutGlobalScopes()->count(),
            'clientes' => ClienteDirecto::withoutGlobalScopes()->count(),
        ];

        // Como si se volviera a sembrar una base que ya estaba sembrada.
        $this->seed(DemoContactosSeeder::class);

        $this->assertSame($antes['usuarios'], Usuario::count());
        $this->assertSame($antes['revendedores'], Revendedor::withoutGlobalScopes()->count());
        $this->assertSame($antes['clientes'], ClienteDirecto::withoutGlobalScopes()->count());

        $this->assertTrue($this->cuenta(DemoContactosSeeder::REVENDEDOR)->hasVerifiedEmail());
        $this->assertTrue($this->cuenta(DemoContactosSeeder::CLIENTE_DIRECTO)->hasVerifiedEmail());
    }

    /** Volver a correr el seeder arregla una base que ya estaba sembrada sin confirmar. */
    public function test_el_seeder_confirma_una_cuenta_que_ya_existia_sin_confirmar(): void
    {
        $this->cuenta(DemoContactosSeeder::REVENDEDOR)
            ->forceFill(['email_verified_at' => null])
            ->save();

        $this->seed(DemoContactosSeeder::class);

        $this->assertTrue($this->cuenta(DemoContactosSeeder::REVENDEDOR)->hasVerifiedEmail());
    }

    public function test_con_la_verificacion_obligatoria_la_cuenta_demo_puede_enviar_un_pedido(): void
    {
        config(['auth.verification.obligatoria' => true]);

        // Que el pedido 1 exista o no es otra cosa: lo que importa es que no
        // la frene el 403 de "confirma tu correo".
        $respuesta = $this->actingAs($this->cuenta(DemoContactosSeeder::REVENDEDOR), 'sanctum')
            ->postJson('/api/pedidos/1/enviar');

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
        $this->assertNotSame(403, $respuesta->status());
    }
}
