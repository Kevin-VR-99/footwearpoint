<?php

namespace Tests\Feature\Auth;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-192 (A3) — El personal manda el enlace de restablecimiento desde el panel.
 *
 * Hasta ahora, quien usaba la app y olvidaba su contrasena se quedaba fuera:
 * el panel no tenia forma de ayudarlo (y el panel tampoco puede volver a
 * ponerle contrasena a una cuenta que ya existe).
 */
class EnviarEnlaceDesdeElPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Notification::fake();
    }

    private function entrarComoAdmin(): Usuario
    {
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        $cuenta = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $cuenta->id))
            ->firstOrFail();
    }

    private function clienteDeJose(): ClienteDirecto
    {
        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();

        return ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();
    }

    public function test_el_admin_le_manda_el_enlace_a_un_revendedor_con_cuenta(): void
    {
        $this->entrarComoAdmin();
        $afiliacion = $this->afiliacionDeMaria();
        $cuenta = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();

        Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $afiliacion->id)
            ->assertSet('avisoEnlaceEsError', false);

        Notification::assertSentTo($cuenta, ResetPassword::class);
    }

    public function test_el_aviso_dice_a_que_correo_se_mando(): void
    {
        $this->entrarComoAdmin();

        $componente = Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $this->afiliacionDeMaria()->id);

        $this->assertStringContainsString('maria.lopez@revendedor.test', $componente->get('avisoEnlace'));
    }

    public function test_tambien_funciona_con_un_cliente_directo(): void
    {
        $this->entrarComoAdmin();
        $cliente = $this->clienteDeJose();
        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();

        Livewire::test('distribuidora.clientes')
            ->call('enviarEnlaceCliente', $cliente->id)
            ->assertSet('avisoEnlaceEsError', false);

        Notification::assertSentTo($cuenta, ResetPassword::class);
    }

    public function test_el_envio_queda_en_la_bitacora_de_auditoria(): void
    {
        $admin = $this->entrarComoAdmin();
        $afiliacion = $this->afiliacionDeMaria();

        Livewire::test('distribuidora.usuarios')->call('enviarEnlaceRevendedor', $afiliacion->id);

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'password.enlace_restablecimiento')
            ->latest('id')
            ->first();

        $this->assertNotNull($registro, 'No quedo el registro en la bitacora.');
        $this->assertSame($admin->id, $registro->usuario_id);
        $this->assertSame('revendedor', $registro->entidad_tipo);
        // assertEquals y no assertSame: MySQL reordena las llaves del JSON.
        $this->assertEquals(['email' => 'maria.lopez@revendedor.test'], $registro->datos_nuevos);
    }

    public function test_si_se_pidio_hace_poco_avisa_que_hay_que_esperar(): void
    {
        $this->entrarComoAdmin();
        $afiliacion = $this->afiliacionDeMaria();

        $componente = Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $afiliacion->id)
            ->call('enviarEnlaceRevendedor', $afiliacion->id);

        $componente->assertSet('avisoEnlaceEsError', true);
        $this->assertStringContainsString('Espera unos minutos', $componente->get('avisoEnlace'));

        // Y el segundo intento no deja un registro de mas en la bitacora.
        $this->assertSame(1, Auditoria::withoutGlobalScopes()
            ->where('accion', 'password.enlace_restablecimiento')->count());
    }

    public function test_no_se_puede_mandar_a_una_cuenta_de_otra_distribuidora(): void
    {
        $this->entrarComoAdmin();

        $otra = Distribuidora::where('slug', '!=', 'calzados-ramirez')->firstOrFail();

        // Un revendedor con cuenta, pero de la OTRA distribuidora.
        $cuentaAjena = Usuario::create([
            'nombre'   => 'Revendedor Ajeno',
            'email'    => 'ajeno@revendedor.test',
            'password' => bcrypt('password'),
            'estado'   => 'activo',
        ]);

        $revendedorAjeno = Revendedor::create([
            'usuario_id' => $cuentaAjena->id,
            'nombre'     => 'Revendedor Ajeno',
            'email'      => 'ajeno@revendedor.test',
            'estado'     => 'activo',
        ]);

        $ajena = RevendedorDistribuidora::withoutGlobalScopes()->create([
            'distribuidora_id' => $otra->id,
            'revendedor_id'    => $revendedorAjeno->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        // El scope de distribuidora hace que ese registro ni siquiera exista
        // para este admin: findOrFail no lo encuentra (en web eso es un 404).
        try {
            Livewire::test('distribuidora.usuarios')->call('enviarEnlaceRevendedor', $ajena->id);
            $this->fail('Se pudo mandar el enlace a una cuenta de otra distribuidora.');
        } catch (ModelNotFoundException $e) {
            // Es lo que se espera.
        }

        Notification::assertNothingSent();
    }
}
