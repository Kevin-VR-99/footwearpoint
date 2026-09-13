<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E3-07 (TG-133) por la API del panel: el admin de distribuidora le da
 * correo y contraseña a un revendedor o cliente, al crearlo o después.
 */
class CuentaAccesoApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function puedeIniciarSesion(string $email, string $password, string $rolEsperado): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('data.rol', $rolEsperado);
    }

    // ------------------------------------------------------------------
    // "Se puede hacer al crear el registro..."
    // ------------------------------------------------------------------

    public function test_el_admin_crea_un_revendedor_con_cuenta_de_una_vez(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/distribuidora/revendedores', [
            'nombre'                       => 'María López',
            'telefono'                     => '9635551111',
            'acceso_email'                 => 'maria@revendedor.test',
            'acceso_password'              => 'clave-segura-1',
            'acceso_password_confirmation' => 'clave-segura-1',
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.tiene_cuenta', true)
            ->assertJsonPath('data.cuenta_email', 'maria@revendedor.test');

        $this->puedeIniciarSesion('maria@revendedor.test', 'clave-segura-1', 'revendedor');
    }

    // ------------------------------------------------------------------
    // "...o después, editándolo"
    // ------------------------------------------------------------------

    public function test_el_admin_activa_la_cuenta_de_un_cliente_que_ya_existia(): void
    {
        $this->comoAdmin();

        // Uno de los clientes de demo, capturado desde antes sin cuenta.
        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        $this->getJson("/api/distribuidora/clientes-directos/{$cliente->id}")
            ->assertOk()
            ->assertJsonPath('data.tiene_cuenta', false);

        $this->patchJson("/api/distribuidora/clientes-directos/{$cliente->id}", [
            'acceso_email'                 => 'ana@cliente.test',
            'acceso_password'              => 'clave-segura-2',
            'acceso_password_confirmation' => 'clave-segura-2',
        ])
            ->assertOk()
            ->assertJsonPath('data.tiene_cuenta', true)
            ->assertJsonPath('data.cuenta_email', 'ana@cliente.test');

        $this->puedeIniciarSesion('ana@cliente.test', 'clave-segura-2', 'cliente_directo');
    }

    public function test_editar_sin_mandar_campos_de_acceso_no_crea_ninguna_cuenta(): void
    {
        $this->comoAdmin();

        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        $this->patchJson("/api/distribuidora/clientes-directos/{$cliente->id}", [
            'telefono' => '9630000000',
        ])
            ->assertOk()
            ->assertJsonPath('data.telefono', '9630000000')
            ->assertJsonPath('data.tiene_cuenta', false);
    }

    // ------------------------------------------------------------------
    // Rechazos
    // ------------------------------------------------------------------

    /**
     * Si el correo ya existe, tampoco se crea el revendedor: el admin corrige
     * el correo y reintenta sin dejar un duplicado en la lista.
     */
    public function test_con_un_correo_repetido_no_se_crea_ni_el_revendedor(): void
    {
        $this->comoAdmin();
        $antes = Revendedor::count();

        $this->postJson('/api/distribuidora/revendedores', [
            'nombre'                       => 'Otra Persona',
            'acceso_email'                 => 'empleado@calzadosramirez.test',
            'acceso_password'              => 'clave-segura-3',
            'acceso_password_confirmation' => 'clave-segura-3',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acceso_email']);

        $this->assertSame($antes, Revendedor::count());
    }

    public function test_la_contrasena_debe_venir_confirmada(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/distribuidora/clientes-directos', [
            'nombre'                       => 'Cliente Nuevo',
            'acceso_email'                 => 'nuevo@cliente.test',
            'acceso_password'              => 'clave-segura-4',
            'acceso_password_confirmation' => 'otra-cosa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acceso_password']);
    }

    public function test_el_correo_y_la_contrasena_van_juntos(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/distribuidora/clientes-directos', [
            'nombre'       => 'Cliente Nuevo',
            'acceso_email' => 'nuevo@cliente.test',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acceso_password']);
    }

    /** Se acordó que esta historia es solo para el admin de distribuidora. */
    public function test_un_empleado_no_puede_activar_cuentas(): void
    {
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        $this->patchJson("/api/distribuidora/clientes-directos/{$cliente->id}", [
            'acceso_email'                 => 'ana@cliente.test',
            'acceso_password'              => 'clave-segura-5',
            'acceso_password_confirmation' => 'clave-segura-5',
        ])->assertStatus(403);

        $this->assertNull($cliente->fresh()->usuario_id);
    }
}
