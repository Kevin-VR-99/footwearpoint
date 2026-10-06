<?php

namespace Tests\Feature\Sprint4;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-216 (Ola 2, K9) — Contacto único (sección 5 del diseño).
 *
 * El nombre y el teléfono de un revendedor o de un cliente directo viven SOLO
 * en su registro de contacto, que es el que edita el panel y el que la app
 * muestra en el perfil. La cuenta guarda únicamente el acceso: correo,
 * contraseña y estado.
 *
 * Ya no hay nada que sincronizar: las dos pantallas escriben en el mismo
 * renglón.
 */
class ContactoUnicoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoApi(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoPanel(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function cuenta(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $this->cuenta(self::MARIA)->id))
            ->firstOrFail();
    }

    private function fichaDeJose(): ClienteDirecto
    {
        return ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $this->cuenta(self::JOSE)->id)
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // La cuenta solo guarda el acceso
    // ------------------------------------------------------------------

    public function test_al_activar_una_cuenta_no_se_copian_nombre_ni_telefono(): void
    {
        $this->comoPanel();

        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('nombre', 'Roberto García'))
            ->firstOrFail();

        $usuario = app(ActivarCuentaAccesoAction::class)
            ->paraRevendedor($afiliacion, 'roberto.cuenta@revendedor.test', 'clave-segura-1');

        $this->assertNull($usuario->nombre);
        $this->assertNull($usuario->telefono);
        $this->assertSame('Roberto García', $usuario->nombreVisible());
    }

    /** El correo del contacto pasa a ser el de acceso y se vacía en el contacto (D4). */
    public function test_el_correo_del_contacto_pasa_a_la_cuenta(): void
    {
        $this->comoPanel();

        $cliente = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        $usuario = app(ActivarCuentaAccesoAction::class)
            ->paraClienteDirecto($cliente, 'ana.cuenta@cliente.test', 'clave-segura-1');

        $this->assertSame('ana.cuenta@cliente.test', $usuario->email);
        $this->assertNull($cliente->fresh()->email, 'El contacto no debe quedarse con otro correo.');
    }

    // ------------------------------------------------------------------
    // Las dos pantallas editan el mismo renglón
    // ------------------------------------------------------------------

    public function test_lo_que_corrige_el_panel_lo_ve_la_app(): void
    {
        $this->comoApi('admin@calzadosramirez.test');

        $this->patchJson("/api/distribuidora/revendedores/{$this->afiliacionDeMaria()->id}", [
            'nombre' => 'María López Ruiz',
            'telefono' => '9631230000',
        ])->assertOk();

        $this->comoApi(self::MARIA);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.usuario.nombre', 'María López Ruiz')
            ->assertJsonPath('data.usuario.telefono', '9631230000');

        // Y la cuenta sigue sin guardar esos datos.
        $this->assertNull($this->cuenta(self::MARIA)->nombre);
    }

    public function test_lo_que_edita_la_app_lo_ve_el_panel(): void
    {
        $this->comoApi(self::JOSE);

        $this->patchJson('/api/perfil', [
            'nombre' => 'José Hernández Díaz',
            'telefono' => '9632223333',
        ])->assertOk();

        $this->assertDatabaseHas('clientes_directos', [
            'id' => $this->fichaDeJose()->id,
            'nombre' => 'José Hernández Díaz',
            'telefono' => '9632223333',
        ]);

        $this->assertNull($this->cuenta(self::JOSE)->nombre);
    }

    /** Un cliente que le compra a dos distribuidoras edita el de la que entró. */
    public function test_el_cliente_con_dos_distribuidoras_edita_solo_el_de_la_que_entro(): void
    {
        $jose = $this->cuenta(self::JOSE);
        $suya = $this->fichaDeJose();

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Calzado Vecino',
            'slug' => 'calzado-vecino-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);

        $fichaEnLaOtra = Tenant::forzar($otra->id, fn () => ClienteDirecto::create([
            'usuario_id' => $jose->id,
            'nombre' => 'José en la otra',
            'telefono' => '9639998888',
            'estado' => 'activo',
        ]));

        $this->comoApi(self::JOSE);

        $this->patchJson('/api/perfil', [
            'nombre' => 'José Hernández Díaz',
            'telefono' => '9632223333',
        ])->assertOk();

        $this->assertSame('José Hernández Díaz', $suya->fresh()->nombre);
        $this->assertSame('José en la otra', $fichaEnLaOtra->fresh()->nombre, 'No debe tocar el de la otra distribuidora.');
    }

    // ------------------------------------------------------------------
    // El correo de quien ya tiene cuenta
    // ------------------------------------------------------------------

    public function test_no_se_puede_cambiar_desde_el_panel_el_correo_de_quien_ya_tiene_cuenta(): void
    {
        $this->comoApi('admin@calzadosramirez.test');

        $this->patchJson("/api/distribuidora/clientes-directos/{$this->fichaDeJose()->id}", [
            'email' => 'otro.correo@cliente.test',
        ])->assertStatus(422);

        $this->assertSame(self::JOSE, $this->cuenta(self::JOSE)->email);
    }

    public function test_el_cliente_sin_cuenta_si_puede_cambiar_su_correo_de_contacto(): void
    {
        $this->comoPanel();

        $ana = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();

        app(GestionarClienteDirectoAction::class)->actualizar($ana, ['email' => 'ana.nueva@cliente.test']);

        $this->assertSame('ana.nueva@cliente.test', $ana->fresh()->email);
    }

    public function test_el_panel_muestra_bloqueado_el_correo_de_quien_tiene_cuenta(): void
    {
        $this->comoPanel();

        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioEditarCliente', $this->fichaDeJose()->id)
            ->assertSee('Es su correo para entrar a la app')
            ->assertSee(self::JOSE);
    }

    // ------------------------------------------------------------------
    // El personal sigue con su nombre en la cuenta
    // ------------------------------------------------------------------

    public function test_el_personal_sigue_usando_el_nombre_de_su_cuenta(): void
    {
        $empleado = $this->cuenta('empleado@calzadosramirez.test');

        $this->assertNotNull($empleado->nombre);
        $this->assertSame($empleado->nombre, $empleado->nombreVisible());
    }

    public function test_crear_personal_sin_nombre_se_rechaza(): void
    {
        $this->comoApi('admin@calzadosramirez.test');

        $this->postJson('/api/auth/register-empleado', [
            'email' => 'empleado.nuevo@calzadosramirez.test',
            'password' => 'clave-segura-1',
            'password_confirmation' => 'clave-segura-1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);
    }

    /** El revendedor tiene un solo registro, compartido entre sus distribuidoras. */
    public function test_el_revendedor_tiene_un_solo_registro_de_contacto(): void
    {
        $maria = $this->cuenta(self::MARIA);

        $this->assertSame(1, Revendedor::withoutGlobalScopes()->where('usuario_id', $maria->id)->count());
    }

    public function test_el_correo_de_un_revendedor_con_cuenta_tampoco_se_cambia_desde_el_panel(): void
    {
        $this->comoPanel();

        $this->expectException(OperacionInvalidaException::class);

        app(\App\Services\Distribuidora\GestionarRevendedorAction::class)
            ->actualizar($this->afiliacionDeMaria(), ['email' => 'otro@revendedor.test']);
    }
}
