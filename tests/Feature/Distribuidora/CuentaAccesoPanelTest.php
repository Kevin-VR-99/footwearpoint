<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E3-07 (TG-133) desde la pantalla Configuración del panel web, que es donde
 * el admin de distribuidora da de alta y edita revendedores y clientes.
 */
class CuentaAccesoPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
    }

    private function puedeIniciarSesionEnLaApp(string $email, string $password, string $rol): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('data.rol', $rol);
    }

    public function test_el_admin_afilia_un_revendedor_con_cuenta_desde_el_panel(): void
    {
        Livewire::test('distribuidora.configuracion')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'María López')
            ->set('revendedor_acceso_email', 'maria@revendedor.test')
            ->set('revendedor_acceso_password', 'clave-segura-1')
            ->set('revendedor_acceso_password_confirmation', 'clave-segura-1')
            ->call('guardarRevendedor')
            ->assertHasNoErrors()
            ->assertSet('mostrandoFormularioRevendedor', false)
            // La contraseña no se queda guardada en la pantalla.
            ->assertSet('revendedor_acceso_password', '');

        $this->puedeIniciarSesionEnLaApp('maria@revendedor.test', 'clave-segura-1', 'revendedor');
    }

    public function test_el_admin_le_da_cuenta_a_un_cliente_que_ya_existia(): void
    {
        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        $pantalla = Livewire::test('distribuidora.configuracion')
            ->call('abrirFormularioEditarCliente', $cliente->id)
            ->assertSet('cliente_cuenta_email_actual', null)
            ->set('cliente_acceso_email', 'ana@cliente.test')
            ->set('cliente_acceso_password', 'clave-segura-2')
            ->set('cliente_acceso_password_confirmation', 'clave-segura-2')
            ->call('guardarCliente')
            ->assertHasNoErrors();

        $this->assertNotNull($cliente->fresh()->usuario_id);

        // Al volver a abrirlo, ya dice que tiene cuenta y no pide campos.
        $pantalla
            ->call('abrirFormularioEditarCliente', $cliente->id)
            ->assertSet('cliente_cuenta_email_actual', 'ana@cliente.test');

        $this->puedeIniciarSesionEnLaApp('ana@cliente.test', 'clave-segura-2', 'cliente_directo');
    }

    /**
     * Regresión: editar solo un dato de contacto no debe pedir contraseña ni
     * crear ninguna cuenta.
     */
    public function test_editar_solo_el_telefono_no_pide_contrasena(): void
    {
        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        Livewire::test('distribuidora.configuracion')
            ->call('abrirFormularioEditarCliente', $cliente->id)
            ->set('cliente_telefono', '9630000000')
            ->call('guardarCliente')
            ->assertHasNoErrors();

        $this->assertSame('9630000000', $cliente->fresh()->telefono);
        $this->assertNull($cliente->fresh()->usuario_id);
    }

    public function test_con_un_correo_repetido_muestra_el_error_y_no_afilia(): void
    {
        $antes = Revendedor::count();

        Livewire::test('distribuidora.configuracion')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Otra Persona')
            ->set('revendedor_acceso_email', 'empleado@calzadosramirez.test')
            ->set('revendedor_acceso_password', 'clave-segura-3')
            ->set('revendedor_acceso_password_confirmation', 'clave-segura-3')
            ->call('guardarRevendedor')
            ->assertHasErrors(['revendedor_acceso_email'])
            // El formulario sigue abierto para que corrija el correo.
            ->assertSet('mostrandoFormularioRevendedor', true);

        $this->assertSame($antes, Revendedor::count());
    }

    public function test_el_correo_sin_contrasena_no_se_acepta(): void
    {
        Livewire::test('distribuidora.configuracion')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'María López')
            ->set('revendedor_acceso_email', 'maria@revendedor.test')
            ->call('guardarRevendedor')
            ->assertHasErrors(['revendedor_acceso_password']);
    }
}
