<?php

namespace Tests\Feature\Distribuidora;

use App\Exceptions\RespuestaErrorApi;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-195 (G4) — Rechazar una distribuidora pendiente, con motivo.
 *
 * E2-01: el admin general revisa los datos antes de aprobar o rechazar, y
 * una distribuidora rechazada no puede operar (su personal no entra al panel).
 */
class RechazarDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MOTIVO = 'El RFC no coincide con la razón social.';
    private const ADMIN_PENDIENTE = 'rosa@zapateriasur.test';

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // Desde TG-186 el admin general ya viene del seeder: aqui solo se usa.
        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => 'admin.general@footwearpoint.test'],
            [
                'nombre'   => 'Admin General',
                'password' => Hash::make('password'),
                'estado'   => 'activo',
            ]
        );

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    /** Una solicitud pendiente con su administrador, como la deja el alta. */
    private function pendiente(): Distribuidora
    {
        return app(CrearDistribuidoraAction::class)->ejecutar(
            [
                'nombre_comercial' => 'Zapatería Sur',
                'slug'             => 'zapateria-sur',
                'razon_social'     => 'Zapatería Sur S.A. de C.V.',
                'rfc'              => 'ZSU010101AB1',
                'email_publico'    => 'contacto@zapateriasur.test',
                'telefono_publico' => '9611234567',
            ],
            [
                'nombre'   => 'Rosa Díaz',
                'email'    => self::ADMIN_PENDIENTE,
                'password' => 'clave-segura-1',
            ],
            false,
        );
    }

    private function rechazada(string $motivo = self::MOTIVO): Distribuidora
    {
        $distribuidora = $this->pendiente();
        app(RechazarDistribuidoraAction::class)->ejecutar($distribuidora, $motivo);

        return $distribuidora->fresh();
    }

    private function comoAdminGeneralEnPanel(): void
    {
        $this->actingAs($this->adminGeneral);
    }

    private function comoAdminGeneralEnApi(): void
    {
        Sanctum::actingAs($this->adminGeneral);
    }

    private function urlRechazar(int $id): string
    {
        return "/api/admin/distribuidoras/{$id}/rechazar";
    }

    // ---------------------------------------------------------------
    // Panel del admin general
    // ---------------------------------------------------------------

    public function test_el_admin_general_rechaza_una_pendiente_con_su_motivo(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnPanel();

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirRechazo', $distribuidora->id)
            ->assertSet('distribuidoraRechazoId', $distribuidora->id)
            ->assertSee('Motivo del rechazo')
            // Contador de caracteres y límite en el campo.
            ->assertSeeHtml('<span x-text="largo">0</span>/300')
            ->assertSeeHtml('maxlength="300"')
            ->set('motivo_rechazo', '  ' . self::MOTIVO . '  ')
            ->call('rechazar')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Distribuidora «Zapatería Sur» rechazada.')
            ->assertSet('distribuidoraRechazoId', null)
            // Debajo de la insignia se ve el motivo.
            ->assertSee('Rechazada')
            ->assertSee('Motivo: ' . self::MOTIVO);

        $distribuidora->refresh();
        $this->assertSame('rechazada', $distribuidora->estado);
        $this->assertSame(self::MOTIVO, $distribuidora->motivo_rechazo);
        $this->assertFalse($distribuidora->marketplace_visible);
        $this->assertSame(0, DB::table('suscripciones')->where('distribuidora_id', $distribuidora->id)->count());

        // Su administrador no se borra ni se desactiva: lo frena el estado.
        $admin = Usuario::where('email', self::ADMIN_PENDIENTE)->firstOrFail();
        $this->assertDatabaseHas('distribuidora_staff', [
            'distribuidora_id' => $distribuidora->id,
            'usuario_id'       => $admin->id,
            'estado'           => 'activo',
        ]);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnPanel();

        foreach (['', '    '] as $motivo) {
            $componente = Livewire::test('admin.distribuidoras-index')
                ->call('abrirRechazo', $distribuidora->id)
                ->set('motivo_rechazo', $motivo)
                ->call('rechazar')
                ->assertHasErrors(['motivo_rechazo' => 'required'])
                ->assertSet('distribuidoraRechazoId', $distribuidora->id);

            $this->assertSame(RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO, $componente->errors()->first('motivo_rechazo'));
        }

        $this->assertSame('pendiente', $distribuidora->fresh()->estado);
    }

    public function test_el_motivo_no_puede_pasar_de_300_caracteres(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnPanel();

        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirRechazo', $distribuidora->id)
            ->set('motivo_rechazo', str_repeat('á', 301))
            ->call('rechazar')
            ->assertHasErrors(['motivo_rechazo' => 'max']);

        $this->assertSame(RechazarDistribuidoraAction::MENSAJE_MOTIVO_LARGO, $componente->errors()->first('motivo_rechazo'));
        $this->assertSame('pendiente', $distribuidora->fresh()->estado);

        // Justo 300 (con acentos) sí cabe en la columna.
        $componente->set('motivo_rechazo', str_repeat('á', 300))
            ->call('rechazar')
            ->assertHasNoErrors();

        $this->assertSame(300, mb_strlen($distribuidora->fresh()->motivo_rechazo));
    }

    public static function estadosNoPendientes(): array
    {
        return [
            'activa'     => ['activa'],
            'suspendida' => ['suspendida'],
            'rechazada'  => ['rechazada'],
        ];
    }

    #[DataProvider('estadosNoPendientes')]
    public function test_solo_se_rechazan_pendientes(string $estado): void
    {
        $distribuidora = $this->pendiente();
        $distribuidora->update(['estado' => $estado]);
        $this->comoAdminGeneralEnPanel();

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirRechazo', $distribuidora->id)
            ->assertSet('mensaje', RechazarDistribuidoraAction::MENSAJE_NO_PENDIENTE)
            ->assertSet('distribuidoraRechazoId', null)
            // Aunque alguien fuerce la ventana, la acción vuelve a revisar.
            ->set('distribuidoraRechazoId', $distribuidora->id)
            ->set('motivo_rechazo', self::MOTIVO)
            ->call('rechazar')
            ->assertSet('mensaje', RechazarDistribuidoraAction::MENSAJE_NO_PENDIENTE)
            ->assertSet('distribuidoraRechazoId', null);

        $distribuidora->refresh();
        $this->assertSame($estado, $distribuidora->estado);
        $this->assertNull($distribuidora->motivo_rechazo);
    }

    public function test_una_rechazada_ya_no_se_puede_aprobar(): void
    {
        $distribuidora = $this->rechazada();
        $this->comoAdminGeneralEnPanel();

        Livewire::test('admin.distribuidoras-index')
            ->call('aprobar', $distribuidora->id)
            ->assertSet('mensaje', 'Solo se pueden aprobar distribuidoras pendientes.');

        $this->assertSame('rechazada', $distribuidora->fresh()->estado);
        $this->assertSame(0, DB::table('suscripciones')->where('distribuidora_id', $distribuidora->id)->count());
    }

    public function test_ver_datos_muestra_la_solicitud_y_su_administrador(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnPanel();

        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $distribuidora->id)
            ->assertSet('distribuidoraDetalleId', $distribuidora->id)
            ->assertSee('Zapatería Sur S.A. de C.V.')
            ->assertSee('ZSU010101AB1')
            ->assertSee('contacto@zapateriasur.test')
            ->assertSee('Rosa Díaz')
            ->assertSee(self::ADMIN_PENDIENTE)
            ->assertSee('Fecha de solicitud')
            ->assertDontSee('Motivo del rechazo');

        // Desde el detalle se puede rechazar.
        $componente->call('abrirRechazo', $distribuidora->id)
            ->set('motivo_rechazo', self::MOTIVO)
            ->call('rechazar')
            ->assertSee('Motivo del rechazo')
            ->assertSee(self::MOTIVO)
            ->call('cerrarDatos')
            ->assertSet('distribuidoraDetalleId', null);
    }

    public function test_un_error_inesperado_muestra_mensaje_amigable_y_va_al_log(): void
    {
        Exceptions::fake();
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnPanel();

        $this->mock(RechazarDistribuidoraAction::class)
            ->shouldReceive('ejecutar')->once()
            ->andThrow(new RuntimeException('SQLSTATE[HY000]: detalle secreto'));

        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirRechazo', $distribuidora->id)
            ->set('motivo_rechazo', self::MOTIVO)
            ->call('rechazar')
            ->assertHasErrors(['motivo_rechazo'])
            ->assertDontSee('SQLSTATE');

        $this->assertSame('No se pudo rechazar la distribuidora. Intenta de nuevo.', $componente->errors()->first('motivo_rechazo'));
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---------------------------------------------------------------
    // API del admin general
    // ---------------------------------------------------------------

    public function test_api_rechaza_una_pendiente(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnApi();

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => '  ' . self::MOTIVO . '  '])
            ->assertOk()
            ->assertJsonPath('data.id', $distribuidora->id)
            ->assertJsonPath('data.estado', 'rechazada')
            ->assertJsonPath('data.motivo_rechazo', self::MOTIVO)
            ->assertJsonPath('message', 'Distribuidora rechazada correctamente.');

        $this->assertSame('rechazada', $distribuidora->fresh()->estado);

        // El listado ya trae el motivo.
        $this->getJson('/api/admin/distribuidoras?estado=rechazada')
            ->assertOk()
            ->assertJsonPath('data.0.id', $distribuidora->id)
            ->assertJsonPath('data.0.motivo_rechazo', self::MOTIVO);
    }

    public function test_api_valida_el_motivo(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnApi();

        $this->postJson($this->urlRechazar($distribuidora->id), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo_rechazo.0', RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO);

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => str_repeat('a', 301)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo_rechazo.0', RechazarDistribuidoraAction::MENSAJE_MOTIVO_LARGO);

        $this->assertSame('pendiente', $distribuidora->fresh()->estado);
    }

    public function test_api_solo_rechaza_pendientes_y_no_dos_veces(): void
    {
        $distribuidora = $this->pendiente();
        $activa = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $this->comoAdminGeneralEnApi();

        $this->postJson($this->urlRechazar($activa->id), ['motivo_rechazo' => self::MOTIVO])
            ->assertUnprocessable()
            ->assertExactJson(['message' => RechazarDistribuidoraAction::MENSAJE_NO_PENDIENTE]);
        $this->assertSame('activa', $activa->fresh()->estado);

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => self::MOTIVO])->assertOk();

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => 'Otro motivo'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => RechazarDistribuidoraAction::MENSAJE_NO_PENDIENTE]);
        $this->assertSame(self::MOTIVO, $distribuidora->fresh()->motivo_rechazo);
    }

    public function test_api_solo_el_admin_general_puede_rechazar(): void
    {
        $distribuidora = $this->pendiente();
        Sanctum::actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => self::MOTIVO])
            ->assertForbidden()
            ->assertExactJson(['message' => RespuestaErrorApi::SIN_PERMISO]);

        $this->getJson("/api/admin/distribuidoras/{$distribuidora->id}")->assertForbidden();

        $this->assertSame('pendiente', $distribuidora->fresh()->estado);
    }

    public function test_api_responde_404_en_espanol_si_no_existe(): void
    {
        $this->comoAdminGeneralEnApi();

        $this->postJson($this->urlRechazar(999999), ['motivo_rechazo' => self::MOTIVO])
            ->assertNotFound()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_ENCONTRADO]);

        $this->getJson('/api/admin/distribuidoras/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_ENCONTRADO]);
    }

    public function test_api_muestra_los_datos_para_revisar_la_solicitud(): void
    {
        $distribuidora = $this->pendiente();
        $this->comoAdminGeneralEnApi();

        $this->getJson("/api/admin/distribuidoras/{$distribuidora->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre_comercial', 'Zapatería Sur')
            ->assertJsonPath('data.razon_social', 'Zapatería Sur S.A. de C.V.')
            ->assertJsonPath('data.rfc', 'ZSU010101AB1')
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.motivo_rechazo', null)
            ->assertJsonPath('data.administradores.0.nombre', 'Rosa Díaz')
            ->assertJsonPath('data.administradores.0.email', self::ADMIN_PENDIENTE)
            ->assertJsonMissingPath('data.administradores.0.password');

        $this->postJson($this->urlRechazar($distribuidora->id), ['motivo_rechazo' => self::MOTIVO])->assertOk();

        $this->getJson("/api/admin/distribuidoras/{$distribuidora->id}")
            ->assertJsonPath('data.estado', 'rechazada')
            ->assertJsonPath('data.motivo_rechazo', self::MOTIVO);
    }

    // ---------------------------------------------------------------
    // Una rechazada no puede operar: su personal no entra al panel
    // ---------------------------------------------------------------

    public function test_el_admin_de_una_rechazada_no_entra_y_ve_el_motivo(): void
    {
        $this->rechazada();

        $componente = Livewire::test('auth.login')
            ->set('email', self::ADMIN_PENDIENTE)
            ->set('password', 'clave-segura-1')
            ->call('login')
            ->assertHasErrors('email')
            ->assertNoRedirect();

        $this->assertSame(
            AccesoPanelWebService::mensajeDistribuidoraRechazada(self::MOTIVO),
            $componente->errors()->first('email')
        );
        $this->assertStringContainsString('Motivo: ' . self::MOTIVO, $componente->errors()->first('email'));
        $this->assertGuest();
    }

    public function test_el_motivo_se_muestra_escapado(): void
    {
        $this->rechazada('Falta el <b>RFC</b> vigente.');

        Livewire::test('auth.login')
            ->set('email', self::ADMIN_PENDIENTE)
            ->set('password', 'clave-segura-1')
            ->call('login')
            ->assertSeeHtml('Falta el &lt;b&gt;RFC&lt;/b&gt; vigente.')
            ->assertDontSeeHtml('<b>RFC</b>');
    }

    public function test_una_sesion_abierta_se_cierra_con_el_motivo(): void
    {
        $distribuidora = $this->pendiente();
        $admin = Usuario::where('email', self::ADMIN_PENDIENTE)->firstOrFail();

        // Entró mientras estaba pendiente (hoy se permite) ...
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        // ... y la rechazan con la sesión abierta.
        app(RechazarDistribuidoraAction::class)->ejecutar($distribuidora, self::MOTIVO);
        Tenant::olvidarCache();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::mensajeDistribuidoraRechazada(self::MOTIVO));

        $this->assertGuest();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Motivo: ' . self::MOTIVO);
    }

    public function test_el_admin_de_una_pendiente_sigue_entrando_como_hasta_ahora(): void
    {
        $this->pendiente();

        Livewire::test('auth.login')
            ->set('email', self::ADMIN_PENDIENTE)
            ->set('password', 'clave-segura-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_motivo_sin_acceso_respeta_al_admin_general_y_al_personal_activo(): void
    {
        $acceso = app(AccesoPanelWebService::class);

        $this->assertNull($acceso->motivoSinAcceso($this->adminGeneral));
        $this->assertNull($acceso->motivoSinAcceso(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail()));
        $this->assertNull($acceso->motivoSinAcceso(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail()));

        $this->rechazada();
        $this->assertSame(
            AccesoPanelWebService::mensajeDistribuidoraRechazada(self::MOTIVO),
            $acceso->motivoSinAcceso(Usuario::where('email', self::ADMIN_PENDIENTE)->firstOrFail())
        );
    }
}
