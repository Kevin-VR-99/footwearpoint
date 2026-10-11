<?php

namespace Tests\Feature\Distribuidora;

use App\Http\Middleware\SoloPersonalPanel;
use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Suscripcion;
use App\Models\Usuario;
use Database\Seeders\AdminGeneralSeeder;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\NotificarCambioEstadoDistribuidoraAction as Notificar;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-196 (G5) — Una distribuidora suspendida o rechazada no puede operar y
 * recibe aviso.
 *
 * E2-02: "Una distribuidora suspendida no puede operar, pero conserva su
 * información. El sistema notifica a la distribuidora del cambio de estado."
 *
 * Se bloquea el panel web (su personal) y la app (sus revendedores y
 * clientes), en el login, en /me y con tokens o sesiones de antes.
 */
class SuspensionDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';
    private const REVENDEDOR = 'maria.lopez@revendedor.test';
    private const CLIENTE = 'jose.hernandez@cliente.test';

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarSesionEnMemoria();

        // Desde TG-186 el admin general ya viene del seeder: aqui solo se usa.
        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
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

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    /** Laravel recuerda usuario y tenant entre peticiones de una misma prueba. */
    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function suspender(): void
    {
        app(SuspenderDistribuidoraAction::class)->ejecutar($this->distribuidora());
        $this->olvidarSesionEnMemoria();
    }

    private function reactivar(): void
    {
        app(ReactivarDistribuidoraAction::class)->ejecutar($this->distribuidora());
        $this->olvidarSesionEnMemoria();
    }

    private function loginApp(string $email)
    {
        $respuesta = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password']);
        $this->olvidarSesionEnMemoria();

        return $respuesta;
    }

    private function tokenApp(string $email): string
    {
        return $this->loginApp($email)->assertOk()->json('data.token');
    }

    private function urlAdmin(string $accion): string
    {
        return "/api/admin/distribuidoras/{$this->distribuidora()->id}/{$accion}";
    }

    private function mensajeSuspendidaApp(): string
    {
        return $this->distribuidora()->fresh()->mensajeSinOperacionParaApp();
    }

    // ---------------------------------------------------------------
    // Suspender y reactivar: panel y API, sin perder información
    // ---------------------------------------------------------------

    public function test_el_admin_general_suspende_y_reactiva_desde_el_panel(): void
    {
        Mail::fake();
        $id = $this->distribuidora()->id;
        $this->actingAs($this->adminGeneral);

        Livewire::test('admin.distribuidoras-index')
            ->call('suspender', $id)
            ->assertSet('mensaje', 'Distribuidora «Calzados Ramírez» suspendida.');

        $this->assertSame('suspendida', $this->distribuidora()->estado);

        Livewire::test('admin.distribuidoras-index')
            ->call('reactivar', $id)
            ->assertSet('mensaje', 'Distribuidora «Calzados Ramírez» reactivada.');

        $this->assertSame('activa', $this->distribuidora()->estado);
    }

    public function test_el_boton_suspender_pide_confirmacion(): void
    {
        $this->actingAs($this->adminGeneral);

        Livewire::test('admin.distribuidoras-index')
            ->assertSeeHtml('wire:confirm="Su personal, revendedores y clientes no podrán usar FootwearPoint hasta reactivarla.');
    }

    public function test_solo_se_suspende_una_activa_y_solo_se_reactiva_una_suspendida(): void
    {
        Mail::fake();
        $id = $this->distribuidora()->id;
        $this->actingAs($this->adminGeneral);

        Livewire::test('admin.distribuidoras-index')
            ->call('reactivar', $id)
            ->assertSet('mensaje', ReactivarDistribuidoraAction::MENSAJE_NO_SUSPENDIDA);

        $this->suspender();
        $this->actingAs($this->adminGeneral);

        Livewire::test('admin.distribuidoras-index')
            ->call('suspender', $id)
            ->assertSet('mensaje', SuspenderDistribuidoraAction::MENSAJE_NO_ACTIVA);

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, 1);
    }

    public function test_api_suspende_y_reactiva_con_los_mismos_mensajes(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->adminGeneral);

        $this->postJson($this->urlAdmin('suspender'))
            ->assertOk()
            ->assertJsonPath('data.estado', 'suspendida')
            ->assertJsonPath('message', 'Distribuidora suspendida correctamente.')
            ->assertJsonPath('aviso_enviado', true);

        $this->postJson($this->urlAdmin('suspender'))
            ->assertStatus(422)
            ->assertExactJson(['message' => SuspenderDistribuidoraAction::MENSAJE_NO_ACTIVA]);

        $this->postJson($this->urlAdmin('reactivar'))
            ->assertOk()
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('message', 'Distribuidora reactivada correctamente.')
            ->assertJsonPath('aviso_enviado', true);

        $this->postJson($this->urlAdmin('reactivar'))
            ->assertStatus(422)
            ->assertExactJson(['message' => ReactivarDistribuidoraAction::MENSAJE_NO_SUSPENDIDA]);
    }

    public function test_suspender_conserva_toda_su_informacion(): void
    {
        Mail::fake();
        $distribuidora = $this->distribuidora();
        $id = $distribuidora->id;

        $antes = Tenant::forzar($id, fn () => [
            'suscripciones' => Suscripcion::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'staff'         => DistribuidoraStaff::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'revendedores'  => RevendedorDistribuidora::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'clientes'      => ClienteDirecto::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
        ]);

        $this->suspender();
        $this->reactivar();

        $despues = Tenant::forzar($id, fn () => [
            'suscripciones' => Suscripcion::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'staff'         => DistribuidoraStaff::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'revendedores'  => RevendedorDistribuidora::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
            'clientes'      => ClienteDirecto::where('distribuidora_id', $id)->get(['id', 'estado'])->toArray(),
        ]);

        $this->assertNotEmpty($antes['staff']);
        $this->assertSame($antes, $despues);
        $this->assertTrue((bool) $this->distribuidora()->marketplace_visible);
    }

    public function test_una_suspendida_no_aparece_en_el_marketplace_y_al_reactivarla_vuelve(): void
    {
        Mail::fake();

        $this->getJson('/api/marketplace')->assertOk()->assertJsonFragment(['nombre_comercial' => 'Calzados Ramírez']);

        $this->suspender();
        $this->getJson('/api/marketplace')->assertOk()->assertJsonMissing(['nombre_comercial' => 'Calzados Ramírez']);

        $this->reactivar();
        $this->getJson('/api/marketplace')->assertOk()->assertJsonFragment(['nombre_comercial' => 'Calzados Ramírez']);
    }

    // ---------------------------------------------------------------
    // Panel web: su personal no opera
    // ---------------------------------------------------------------

    public function test_el_personal_de_una_suspendida_no_entra_al_panel(): void
    {
        Mail::fake();
        $this->suspender();

        foreach ([self::ADMIN, self::EMPLEADO] as $email) {
            $componente = Livewire::test('auth.login')
                ->set('email', $email)
                ->set('password', 'password')
                ->call('login')
                ->assertHasErrors('email')
                ->assertNoRedirect();

            $this->assertSame(AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_SUSPENDIDA, $componente->errors()->first('email'));
            $this->assertGuest();
        }
    }

    public function test_una_sesion_abierta_se_cierra_al_suspender_y_vuelve_al_reactivar(): void
    {
        Mail::fake();
        $admin = Usuario::where('email', self::ADMIN)->firstOrFail();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        // Se suspende con su sesión abierta (aquí no se olvida al usuario).
        app(SuspenderDistribuidoraAction::class)->ejecutar($this->distribuidora());
        Tenant::olvidarCache();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_SUSPENDIDA);
        $this->assertGuest();

        $this->reactivar();

        Livewire::test('auth.login')
            ->set('email', self::ADMIN)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_un_componente_del_panel_ya_no_ve_datos_de_una_suspendida(): void
    {
        Mail::fake();
        $admin = Usuario::where('email', self::ADMIN)->firstOrFail();
        $this->actingAs($admin);

        Livewire::test('stock.index')->assertStatus(200);

        $this->suspender();
        $this->actingAs($admin);

        Livewire::test('stock.index')->assertStatus(403);
    }

    public function test_las_acciones_de_livewire_vuelven_a_revisar_el_acceso_al_panel(): void
    {
        $this->assertContains(
            SoloPersonalPanel::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware()
        );
    }

    public function test_el_admin_general_y_el_login_siguen_igual(): void
    {
        Mail::fake();
        $this->suspender();

        Livewire::test('auth.login')
            ->set('email', 'admin.general@footwearpoint.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertNull(app(AccesoPanelWebService::class)->motivoSinAcceso($this->adminGeneral));
    }

    // ---------------------------------------------------------------
    // App: revendedores y clientes no operan
    // ---------------------------------------------------------------

    public function test_revendedores_y_clientes_de_una_suspendida_no_entran_a_la_app(): void
    {
        Mail::fake();
        $this->suspender();
        $mensaje = $this->mensajeSuspendidaApp();

        $this->assertStringContainsString('Calzados Ramírez está suspendida', $mensaje);
        $this->assertStringContainsString('se conservan', $mensaje);

        foreach ([self::REVENDEDOR, self::CLIENTE] as $email) {
            $respuesta = $this->loginApp($email)
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', $mensaje);

            $this->assertNull($respuesta->json('data.token'));
        }

        $this->assertSame(0, Usuario::whereIn('email', [self::REVENDEDOR, self::CLIENTE])->withCount('tokens')->get()->sum('tokens_count'));
    }

    public function test_el_personal_de_una_suspendida_tampoco_obtiene_token_en_la_app(): void
    {
        Mail::fake();
        $this->suspender();

        $respuesta = $this->loginApp(self::ADMIN)->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertNull($respuesta->json('data.token'));
        $this->assertSame(0, Usuario::where('email', self::ADMIN)->firstOrFail()->tokens()->count());
    }

    public function test_un_token_de_antes_responde_401_en_me_y_se_revoca(): void
    {
        Mail::fake();
        $token = $this->tokenApp(self::REVENDEDOR);

        $this->suspender();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => $this->mensajeSuspendidaApp()]);
        $this->olvidarSesionEnMemoria();

        // Aunque la reactiven, ese token ya se revocó: tiene que volver a entrar.
        $this->reactivar();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->olvidarSesionEnMemoria();

        $nuevo = $this->tokenApp(self::REVENDEDOR);
        $this->withToken($nuevo)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.distribuidora_id', $this->distribuidora()->id);
    }

    public function test_un_token_de_antes_ya_no_sirve_en_otros_endpoints(): void
    {
        Mail::fake();
        $token = $this->tokenApp(self::REVENDEDOR);

        $this->withToken($token)->getJson('/api/pedidos')->assertOk();
        $this->olvidarSesionEnMemoria();

        $this->suspender();

        $this->withToken($token)->getJson('/api/pedidos')->assertStatus(403);
        $this->olvidarSesionEnMemoria();

        $this->reactivar();

        $this->withToken($token)->getJson('/api/pedidos')->assertOk();
    }

    public function test_un_revendedor_con_otra_distribuidora_activa_entra_con_esa(): void
    {
        Mail::fake();

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Zapatería Norte',
            'razon_social'     => 'Zapatería Norte S.A. de C.V.',
            'rfc'              => 'ZNO010101AB1',
            'slug'             => 'zapateria-norte',
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);

        // Por su cuenta: desde TG-216 el correo del contacto se vacia al activarla.
        $revendedor = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::REVENDEDOR)->value('id'))
            ->firstOrFail();
        RevendedorDistribuidora::create([
            'distribuidora_id' => $otra->id,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $this->suspender();

        $this->loginApp(self::REVENDEDOR)
            ->assertOk()
            ->assertJsonPath('data.distribuidora_id', $otra->id);

        $this->assertNull(Tenant::distribuidoraSinOperacionDe($revendedor->usuario_id));
    }

    public function test_una_rechazada_tampoco_opera_en_la_app(): void
    {
        $this->distribuidora()->update(['estado' => 'rechazada']);
        $this->olvidarSesionEnMemoria();

        $mensaje = $this->distribuidora()->mensajeSinOperacionParaApp();
        $this->assertStringContainsString('no fue aprobada', $mensaje);

        $this->loginApp(self::CLIENTE)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', $mensaje);
    }

    public function test_una_afiliacion_suspendida_sigue_como_antes(): void
    {
        Mail::fake();
        // Por su cuenta: desde TG-216 el correo del contacto se vacia al activarla.
        $revendedor = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::REVENDEDOR)->value('id'))
            ->firstOrFail();

        RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->update(['estado' => 'suspendido']);

        // Con la distribuidora activa no hay aviso de distribuidora: se
        // conserva el comportamiento de antes (entra, sin distribuidora).
        $this->assertNull(Tenant::distribuidoraSinOperacionDe($revendedor->usuario_id));

        $this->loginApp(self::REVENDEDOR)
            ->assertOk()
            ->assertJsonPath('data.distribuidora_id', null);
    }

    // ---------------------------------------------------------------
    // Aviso por correo a la distribuidora
    // ---------------------------------------------------------------

    public function test_al_suspender_y_reactivar_se_avisa_a_sus_administradores(): void
    {
        Mail::fake();

        $this->suspender();

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo(self::ADMIN)
            && $mail->evento === Notificar::SUSPENDIDA
            && $mail->nombreDistribuidora === 'Calzados Ramírez');
        Mail::assertNotSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo(self::EMPLEADO));

        $this->reactivar();

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo(self::ADMIN)
            && $mail->evento === Notificar::REACTIVADA);
        Mail::assertSent(CambioEstadoDistribuidoraMail::class, 2);
    }

    public function test_al_aprobar_y_al_rechazar_tambien_se_avisa(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->adminGeneral);

        $aprobar = $this->pendiente('Zapatería Este', 'zapateria-este', 'ZES010101AB1', 'rosa@zapateriaeste.test');
        $rechazar = $this->pendiente('Zapatería Oeste', 'zapateria-oeste', 'ZOE010101AB1', 'luis@zapateriaoeste.test');

        $this->postJson("/api/admin/distribuidoras/{$aprobar->id}/aprobar")
            ->assertOk()
            ->assertJsonPath('message', 'Distribuidora aprobada correctamente.')
            ->assertJsonPath('aviso_enviado', true);

        $this->postJson("/api/admin/distribuidoras/{$rechazar->id}/rechazar", ['motivo_rechazo' => 'El RFC no coincide.'])
            ->assertOk()
            ->assertJsonPath('aviso_enviado', true);

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo('rosa@zapateriaeste.test')
            && $mail->evento === Notificar::APROBADA);
        Mail::assertSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo('luis@zapateriaoeste.test')
            && $mail->evento === Notificar::RECHAZADA
            && $mail->motivo === 'El RFC no coincide.');
    }

    public function test_el_correo_esta_en_espanol_y_sin_detalles_tecnicos(): void
    {
        $suspendida = new CambioEstadoDistribuidoraMail('Calzados Ramírez', Notificar::SUSPENDIDA, 'Ana Ramírez');
        $suspendida->assertHasSubject('«Calzados Ramírez» fue suspendida en FootwearPoint');
        $suspendida->assertSeeInHtml('Hola Ana Ramírez');
        $suspendida->assertSeeInHtml('Tu información se conserva.');
        $suspendida->assertSeeInHtml('Comunícate con FootwearPoint para reactivarla.');

        $rechazada = new CambioEstadoDistribuidoraMail('Zapatería <b>Sur</b>', Notificar::RECHAZADA, null, 'Falta el <i>RFC</i>.');
        $rechazada->assertSeeInHtml('Motivo:');
        $rechazada->assertSeeInHtml('Falta el &lt;i&gt;RFC&lt;/i&gt;.', false);
        $rechazada->assertDontSeeInHtml('<i>RFC</i>', false);

        $reactivada = new CambioEstadoDistribuidoraMail('Calzados Ramírez', Notificar::REACTIVADA);
        $reactivada->assertSeeInHtml(route('login'));

        foreach ([$suspendida, $rechazada, $reactivada] as $mail) {
            $mail->assertDontSeeInHtml('Exception');
            $mail->assertDontSeeInHtml('SQLSTATE');
        }
    }

    public function test_sin_administradores_se_avisa_a_su_correo_publico(): void
    {
        Mail::fake();
        $id = $this->distribuidora()->id;

        Tenant::forzar($id, fn () => DistribuidoraStaff::where('distribuidora_id', $id)->update(['estado' => 'inactivo']));

        $this->suspender();

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo('contacto@calzadosramirez.test'));
    }

    public function test_si_el_correo_falla_la_suspension_se_queda_y_el_admin_lo_sabe(): void
    {
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído: 10.0.0.5'));

        $this->actingAs($this->adminGeneral);

        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('suspender', $this->distribuidora()->id);

        $this->assertSame('suspendida', $this->distribuidora()->estado);
        $this->assertSame(
            'Distribuidora «Calzados Ramírez» suspendida. No se pudo enviar el aviso por correo a la distribuidora.',
            $componente->get('mensaje')
        );
        Exceptions::assertReported(RuntimeException::class);

        Sanctum::actingAs($this->adminGeneral);

        $this->postJson($this->urlAdmin('reactivar'))
            ->assertOk()
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('aviso_enviado', false)
            ->assertJsonPath('message', 'Distribuidora reactivada correctamente, pero no se pudo enviar el aviso por correo a la distribuidora.')
            ->assertDontSee('SMTP');
    }

    /** Una solicitud pendiente con su administrador, como la deja el alta. */
    private function pendiente(string $nombre, string $slug, string $rfc, string $emailAdmin): Distribuidora
    {
        return app(CrearDistribuidoraAction::class)->ejecutar(
            [
                'nombre_comercial' => $nombre,
                'slug'             => $slug,
                'razon_social'     => "{$nombre} S.A. de C.V.",
                'rfc'              => $rfc,
                'email_publico'    => "contacto@{$slug}.test",
                'telefono_publico' => '9611234567',
            ],
            [
                'nombre'   => 'Admin ' . $nombre,
                'email'    => $emailAdmin,
                'password' => 'clave-segura-1',
            ],
            false,
        );
    }
}
