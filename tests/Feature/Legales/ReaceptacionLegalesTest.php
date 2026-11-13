<?php

namespace Tests\Feature\Legales;

use App\Models\AceptacionLegal;
use App\Models\Usuario;
use App\Support\DocumentosLegales;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-323 (K24) — Si cambia la versión del aviso o de los términos, se pide
 * aceptar la nueva en el panel y en la app, y la aceptación guarda la versión
 * que de verdad está vigente.
 */
class ReaceptacionLegalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLIENTE = 'jose.hernandez@cliente.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const ADMIN_GENERAL = AdminGeneralSeeder::EMAIL;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function usuario(string $correo): Usuario
    {
        return Usuario::where('email', $correo)->firstOrFail();
    }

    /** Publica una versión nueva del aviso (como lo haría K23). */
    private function nuevoAviso(string $version = '9.9'): void
    {
        config([
            'legal.documentos.aviso_privacidad.version' => $version,
            'legal.documentos.aviso_privacidad.fecha_actualizacion' => '1 de enero de 2027',
        ]);
    }

    private function token(string $correo): string
    {
        return $this->usuario($correo)->createToken('prueba')->plainTextToken;
    }

    private function api(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // ------------------------------------------------------------------
    // Qué cuenta como aceptado
    // ------------------------------------------------------------------

    public function test_con_las_versiones_vigentes_aceptadas_no_falta_nada(): void
    {
        $this->api($this->token(self::CLIENTE))->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.legales_aceptados', true)
            ->assertJsonPath('data.legales_pendientes', []);
    }

    public function test_al_subir_la_version_del_aviso_se_pide_solo_el_aviso(): void
    {
        $this->nuevoAviso();

        $this->api($this->token(self::CLIENTE))->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.legales_aceptados', false)
            ->assertJsonCount(1, 'data.legales_pendientes')
            ->assertJsonPath('data.legales_pendientes.0.tipo_documento', 'aviso_privacidad')
            ->assertJsonPath('data.legales_pendientes.0.nombre', 'Aviso de privacidad')
            ->assertJsonPath('data.legales_pendientes.0.version', '9.9')
            ->assertJsonPath('data.legales_pendientes.0.fecha_actualizacion', '1 de enero de 2027')
            ->assertJsonPath('data.legales_pendientes.0.url', route('aviso-privacidad'));
    }

    public function test_quien_nunca_acepto_tiene_los_dos_pendientes(): void
    {
        $cliente = $this->usuario(self::CLIENTE);
        AceptacionLegal::where('usuario_id', $cliente->id)->delete();

        $this->assertSame([DocumentosLegales::AVISO, DocumentosLegales::TERMINOS], DocumentosLegales::pendientes($cliente));
    }

    public function test_el_admin_general_no_tiene_que_aceptar(): void
    {
        $admin = $this->usuario(self::ADMIN_GENERAL);
        AceptacionLegal::where('usuario_id', $admin->id)->delete();
        $this->nuevoAviso();

        $this->assertTrue(DocumentosLegales::exento($admin));
        $this->assertSame([], DocumentosLegales::pendientes($admin));
        $this->assertFalse(DocumentosLegales::exento($this->usuario(self::EMPLEADO)));
    }

    // ------------------------------------------------------------------
    // La API guarda la versión del servidor y conserva el historial
    // ------------------------------------------------------------------

    /** La app 1.3.3 manda «1.0» fija: se ignora y se guarda la vigente. */
    public function test_la_api_ignora_la_version_que_manda_la_app_y_conserva_la_anterior(): void
    {
        $anterior = DocumentosLegales::version(DocumentosLegales::AVISO);
        $this->nuevoAviso();
        $cliente = $this->usuario(self::CLIENTE);

        $this->api($this->token(self::CLIENTE))->postJson('/api/auth/aceptar-legales', [
            'documentos' => [
                ['tipo_documento' => 'aviso_privacidad', 'version' => '1.0'],
                ['tipo_documento' => 'terminos_condiciones', 'version' => '1.0'],
            ],
        ])->assertCreated()
            ->assertJsonPath('legales_aceptados', true)
            ->assertJsonPath('data.0.version', '9.9');

        $this->assertDatabaseMissing('aceptaciones_legales', ['usuario_id' => $cliente->id, 'version' => '1.0']);
        // La aceptación anterior sigue ahí, junto a la nueva.
        $this->assertSame(
            [$anterior, '9.9'],
            AceptacionLegal::where('usuario_id', $cliente->id)->where('tipo_documento', 'aviso_privacidad')
                ->orderBy('version')->pluck('version')->all()
        );
    }

    public function test_la_api_rechaza_documentos_que_no_existen(): void
    {
        $this->api($this->token(self::CLIENTE))->postJson('/api/auth/aceptar-legales', [
            'documentos' => [['tipo_documento' => 'otro_documento']],
        ])->assertStatus(422);
    }

    public function test_aceptar_dos_veces_la_misma_version_no_duplica(): void
    {
        $this->nuevoAviso();
        $token = $this->token(self::CLIENTE);

        $this->api($token)->postJson('/api/auth/aceptar-legales')->assertCreated();
        $this->api($token)->postJson('/api/auth/aceptar-legales')->assertCreated();

        $this->assertSame(1, AceptacionLegal::where('usuario_id', $this->usuario(self::CLIENTE)->id)
            ->where('tipo_documento', 'aviso_privacidad')->where('version', '9.9')->count());
    }

    // ------------------------------------------------------------------
    // El candado en la API
    // ------------------------------------------------------------------

    public function test_con_algo_pendiente_la_api_solo_deja_aceptar_ver_la_sesion_y_salir(): void
    {
        $this->nuevoAviso();
        $token = $this->token(self::CLIENTE);

        $this->api($token)->getJson('/api/vales')
            ->assertForbidden()
            ->assertJsonPath('legales_pendientes', true);
        $this->api($token)->getJson('/api/auth/me')->assertOk();

        $this->api($token)->postJson('/api/auth/aceptar-legales')->assertCreated();

        $this->api($token)->getJson('/api/vales')->assertOk();
    }

    // ------------------------------------------------------------------
    // El candado en el panel
    // ------------------------------------------------------------------

    public function test_en_el_panel_con_algo_pendiente_lleva_a_aceptar_y_luego_regresa(): void
    {
        $this->nuevoAviso();
        $empleado = $this->usuario(self::EMPLEADO);
        $this->actingAs($empleado);

        $this->get(route('dashboard'))->assertRedirect(route('legales.aceptar'));
        $this->get(route('legales.aceptar'))->assertOk()
            ->assertSee('Actualizamos un documento')
            ->assertSee('versión 9.9, 1 de enero de 2027', false)
            ->assertSee('Por aceptar');

        Livewire::test('auth.aceptar-legales')
            ->set('aviso', true)
            ->call('aceptar')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('aceptaciones_legales', [
            'usuario_id' => $empleado->id, 'tipo_documento' => 'aviso_privacidad', 'version' => '9.9',
        ]);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_las_paginas_publicas_no_se_bloquean_aunque_falte_aceptar(): void
    {
        $this->nuevoAviso();
        $this->actingAs($this->usuario(self::EMPLEADO));

        $this->get(route('aviso-privacidad'))->assertOk()->assertSee('Versión 9.9');
        $this->get(route('terminos'))->assertOk();
    }

    public function test_el_admin_general_entra_a_su_panel_sin_aceptar(): void
    {
        $admin = $this->usuario(self::ADMIN_GENERAL);
        AceptacionLegal::where('usuario_id', $admin->id)->delete();
        $this->nuevoAviso();
        $this->actingAs($admin);

        $this->get(route('dashboard'))->assertDontSee('Documentos legales');
        $this->assertFalse($this->get(route('dashboard'))->isRedirect(route('legales.aceptar')));
    }

    /** Regresión: en una página de Livewire el candado redirige, no truena con 500. */
    public function test_en_cualquier_pagina_del_panel_redirige_sin_tronar(): void
    {
        $this->nuevoAviso();
        $this->actingAs($this->usuario(self::EMPLEADO));

        $this->get('/admin')->assertRedirect(route('legales.aceptar'));
    }

    /** Aceptar los legales va antes del pago: nadie queda dando vueltas. */
    public function test_los_candados_de_pago_dejan_pasar_la_pantalla_de_aceptar(): void
    {
        foreach ([\App\Http\Middleware\CobroInicialPendiente::class, \App\Http\Middleware\SoloPagoPorFaltaDePago::class] as $candado) {
            $web = (new \ReflectionClassConstant($candado, 'RUTAS_WEB'))->getValue();
            $api = (new \ReflectionClassConstant($candado, 'RUTAS_API'))->getValue();

            $this->assertContains('legales.aceptar', $web, $candado);
            $this->assertContains('api/auth/aceptar-legales', $api, $candado);
        }
    }
}
