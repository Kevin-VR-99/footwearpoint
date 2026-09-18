<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-147 — Sincronización panel → app.
 *
 * Si el admin corrige nombre o teléfono de un revendedor o cliente directo
 * desde el panel, la cuenta de la app (usuarios) tiene que quedar igual. El
 * sentido contrario (app → panel) ya lo prueba PerfilUsuarioTest (TG-110).
 *
 * Usa las cuentas de demo: María (revendedora) y José (cliente directo).
 * Ana García es un cliente SIN cuenta.
 */
class SincronizarCuentaDesdePanelTest extends TestCase
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

    private function comoAdminApi(): void
    {
        Sanctum::actingAs($this->cuenta('admin@calzadosramirez.test'));
        Tenant::olvidarCache();
    }

    private function comoAdminPanel(): void
    {
        $this->actingAs($this->cuenta('admin@calzadosramirez.test'));
        Tenant::olvidarCache();
    }

    // ------------------------------------------------------------------
    // Desde la API del panel
    // ------------------------------------------------------------------

    /** La prueba de fondo: lo que corrigió el admin es lo que ve la app. */
    public function test_el_admin_corrige_a_una_revendedora_y_la_app_ve_el_dato_nuevo(): void
    {
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/revendedores/{$this->afiliacionDeMaria()->id}", [
            'nombre'   => 'María López Ruiz',
            'telefono' => '9631230000',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => self::MARIA, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.usuario.nombre', 'María López Ruiz')
            ->assertJsonPath('data.usuario.telefono', '9631230000');
    }

    public function test_editar_solo_el_telefono_no_toca_el_nombre_de_la_cuenta(): void
    {
        $nombreAntes = $this->cuenta(self::JOSE)->nombre;
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/clientes-directos/{$this->fichaDeJose()->id}", [
            'telefono' => '9639990000',
        ])->assertOk();

        $this->assertSame('9639990000', $this->cuenta(self::JOSE)->telefono);
        $this->assertSame($nombreAntes, $this->cuenta(self::JOSE)->nombre);
    }

    public function test_cambiar_solo_el_estado_o_el_codigo_no_toca_la_cuenta(): void
    {
        $antes = $this->cuenta(self::MARIA)->only(['nombre', 'telefono']);
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/revendedores/{$this->afiliacionDeMaria()->id}", [
            'codigo_interno' => 'REV-999',
        ])->assertOk();

        $this->assertSame($antes, $this->cuenta(self::MARIA)->only(['nombre', 'telefono']));
    }

    /** El correo NO se sincroniza: el de contacto y el de la cuenta pueden ser distintos. */
    public function test_cambiar_el_correo_de_contacto_no_cambia_el_correo_de_la_cuenta(): void
    {
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/clientes-directos/{$this->fichaDeJose()->id}", [
            'email' => 'otro.contacto@cliente.test',
        ])->assertOk();

        $this->assertSame(self::JOSE, $this->cuenta(self::JOSE)->email);
    }

    public function test_editar_un_cliente_sin_cuenta_sigue_funcionando(): void
    {
        $ana = ClienteDirecto::where('email', 'ana.garcia@cliente.test')->firstOrFail();
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/clientes-directos/{$ana->id}", [
            'nombre' => 'Ana García Pérez',
        ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Ana García Pérez');
    }

    public function test_corregir_a_una_persona_no_toca_la_cuenta_de_otra(): void
    {
        $antesJose = $this->cuenta(self::JOSE)->only(['nombre', 'telefono']);
        $this->comoAdminApi();

        $this->patchJson("/api/distribuidora/revendedores/{$this->afiliacionDeMaria()->id}", [
            'nombre' => 'María López Ruiz',
        ])->assertOk();

        $this->assertSame($antesJose, $this->cuenta(self::JOSE)->only(['nombre', 'telefono']));
    }

    // ------------------------------------------------------------------
    // Desde la pantalla Configuración
    // ------------------------------------------------------------------

    public function test_desde_la_pantalla_configuracion_tambien_se_actualiza_la_cuenta(): void
    {
        $this->comoAdminPanel();

        Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioEditarRevendedor', $this->afiliacionDeMaria()->id)
            ->set('revendedor_nombre', 'María López Ruiz')
            ->set('revendedor_telefono', '9631230000')
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        $this->assertSame('María López Ruiz', $this->cuenta(self::MARIA)->nombre);
        $this->assertSame('9631230000', $this->cuenta(self::MARIA)->telefono);
    }

    /** Mismo criterio que el perfil de la app: teléfono vacío = sin teléfono, en los dos lugares. */
    public function test_un_telefono_borrado_en_el_panel_queda_sin_telefono_en_los_dos_lugares(): void
    {
        $this->comoAdminPanel();

        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioEditarCliente', $this->fichaDeJose()->id)
            ->set('cliente_telefono', '')
            ->call('guardarCliente')
            ->assertHasNoErrors();

        $this->assertNull($this->fichaDeJose()->telefono);
        $this->assertNull($this->cuenta(self::JOSE)->telefono);
    }
}
