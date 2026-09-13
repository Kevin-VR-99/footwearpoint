<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * E3-07 (TG-133) — Activar cuenta de acceso para revendedor o cliente directo.
 *
 * Criterio de la historia: sin esto el revendedor o cliente no puede usar la
 * app. Por eso la prueba principal no revisa solo la base: activa la cuenta
 * y luego inicia sesión de verdad por la API.
 */
class ActivarCuentaAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function crearDistribuidoraSinRoles(): Distribuidora
    {
        return Distribuidora::create([
            'nombre_comercial' => 'Zapatería Nueva (prueba)',
            'razon_social'     => 'Zapatería Nueva S.A. de C.V.',
            'rfc'              => 'ZNU010101' . strtoupper(substr(uniqid(), -3)),
            'slug'             => 'zapateria-nueva-' . uniqid(),
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);
    }

    /** Afilia por el mismo camino que usan la API y el panel. */
    private function afiliarRevendedor(int $distribuidoraId, string $nombre): RevendedorDistribuidora
    {
        return Tenant::forzar($distribuidoraId, fn () => app(GestionarRevendedorAction::class)->afiliar([
            'nombre'   => $nombre,
            'telefono' => '9630000000',
        ]));
    }

    private function crearCliente(int $distribuidoraId, string $nombre): ClienteDirecto
    {
        return Tenant::forzar($distribuidoraId, fn () => app(GestionarClienteDirectoAction::class)->crear([
            'nombre' => $nombre,
        ]));
    }

    private function accion(): ActivarCuentaAccesoAction
    {
        return app(ActivarCuentaAccesoAction::class);
    }

    // ------------------------------------------------------------------
    // Lo que tiene que pasar
    // ------------------------------------------------------------------

    public function test_un_revendedor_con_cuenta_activada_puede_iniciar_sesion_en_la_app(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $afiliacion = $this->afiliarRevendedor($distribuidoraA->id, 'María López');

        $usuario = $this->accion()->paraRevendedor($afiliacion, 'maria@revendedor.test', 'clave-segura-1');

        // Quedó ligado por usuario_id, que antes siempre iba vacío.
        $this->assertSame($usuario->id, $afiliacion->revendedor->fresh()->usuario_id);
        $this->assertSame('María López', $usuario->nombre);
        $this->assertTrue(Hash::check('clave-segura-1', $usuario->password));

        $this->postJson('/api/auth/login', [
            'email'    => 'maria@revendedor.test',
            'password' => 'clave-segura-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.rol', 'revendedor')
            ->assertJsonPath('data.distribuidora_id', $distribuidoraA->id);
    }

    public function test_un_cliente_directo_con_cuenta_activada_puede_iniciar_sesion_en_la_app(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $cliente = $this->crearCliente($distribuidoraA->id, 'Ana García');

        $usuario = $this->accion()->paraClienteDirecto($cliente, 'ana@cliente.test', 'clave-segura-2');

        $this->assertSame($usuario->id, $cliente->fresh()->usuario_id);

        $this->postJson('/api/auth/login', [
            'email'    => 'ana@cliente.test',
            'password' => 'clave-segura-2',
        ])
            ->assertOk()
            ->assertJsonPath('data.rol', 'cliente_directo')
            ->assertJsonPath('data.distribuidora_id', $distribuidoraA->id);
    }

    /**
     * En una distribuidora real (no la demo) nadie ha creado todavía los
     * roles de la app: se crean en el momento de activar la primera cuenta.
     */
    public function test_en_una_distribuidora_sin_roles_crea_el_rol_al_activar_la_primera_cuenta(): void
    {
        $nueva = $this->crearDistribuidoraSinRoles();
        $this->assertFalse(Role::where('team_id', $nueva->id)->where('name', 'revendedor')->exists());

        $afiliacion = $this->afiliarRevendedor($nueva->id, 'Roberto García');
        $this->accion()->paraRevendedor($afiliacion, 'roberto@revendedor.test', 'clave-segura-3');

        $this->assertTrue(Role::where('team_id', $nueva->id)->where('name', 'revendedor')->exists());

        $this->postJson('/api/auth/login', [
            'email'    => 'roberto@revendedor.test',
            'password' => 'clave-segura-3',
        ])
            ->assertOk()
            ->assertJsonPath('data.rol', 'revendedor')
            ->assertJsonPath('data.distribuidora_id', $nueva->id);
    }

    // ------------------------------------------------------------------
    // Lo que tiene que rechazar, sin dejar nada a medias
    // ------------------------------------------------------------------

    public function test_no_deja_activar_una_segunda_cuenta_al_mismo_registro(): void
    {
        $afiliacion = $this->afiliarRevendedor($this->distribuidoraA()->id, 'María López');
        $this->accion()->paraRevendedor($afiliacion, 'maria@revendedor.test', 'clave-segura-1');

        try {
            $this->accion()->paraRevendedor($afiliacion->fresh(), 'otro@revendedor.test', 'clave-segura-9');
            $this->fail('Debió rechazar la segunda cuenta.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('acceso_email', $e->errors());
        }

        $this->assertFalse(Usuario::where('email', 'otro@revendedor.test')->exists());
    }

    public function test_no_deja_usar_un_correo_que_ya_tiene_cuenta(): void
    {
        $cliente = $this->crearCliente($this->distribuidoraA()->id, 'Ana García');

        try {
            $this->accion()->paraClienteDirecto($cliente, 'empleado@calzadosramirez.test', 'clave-segura-4');
            $this->fail('Debió rechazar el correo repetido.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('acceso_email', $e->errors());
        }

        // Nada a medias: el cliente sigue sin cuenta.
        $this->assertNull($cliente->fresh()->usuario_id);
    }

    /**
     * Regla acordada: una cuenta = una distribuidora. María ya usa la app con
     * la distribuidora A; la distribuidora B la afilia también y quiere darle
     * cuenta con el mismo correo. Se rechaza: para B necesita otro correo.
     */
    public function test_una_misma_persona_no_puede_tener_la_misma_cuenta_en_dos_distribuidoras(): void
    {
        $afiliacionEnA = $this->afiliarRevendedor($this->distribuidoraA()->id, 'María López');
        $this->accion()->paraRevendedor($afiliacionEnA, 'maria@revendedor.test', 'clave-segura-1');

        $distribuidoraB = $this->crearDistribuidoraSinRoles();
        $afiliacionEnB = $this->afiliarRevendedor($distribuidoraB->id, 'María López');

        $this->expectException(ValidationException::class);

        $this->accion()->paraRevendedor($afiliacionEnB, 'maria@revendedor.test', 'otra-clave-1');
    }

    // ------------------------------------------------------------------
    // Datos de demo
    // ------------------------------------------------------------------

    /**
     * Las cuentas de demo existen para que cualquiera del equipo pueda probar
     * la app de verdad. Y el seeder tiene que poder correrse otra vez sin
     * tronar por intentar crear la misma cuenta.
     */
    public function test_las_cuentas_de_demo_entran_a_la_app_y_el_seeder_se_puede_repetir(): void
    {
        $this->seed(\Database\Seeders\DemoContactosSeeder::class);

        $this->postJson('/api/auth/login', ['email' => 'maria.lopez@revendedor.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.rol', 'revendedor');

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => 'jose.hernandez@cliente.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.rol', 'cliente_directo');
    }
}
