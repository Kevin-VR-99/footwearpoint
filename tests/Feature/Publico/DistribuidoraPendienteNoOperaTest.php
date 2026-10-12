<?php

namespace Tests\Feature\Publico;

use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Models\PlanSuscripcion;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-220 (E2-08) — Una distribuidora pendiente no opera.
 *
 * Antes sí lo hacía, porque solo el admin general podía crearla y ya la
 * había revisado. Con el formulario público eso dejaría entrar a cualquiera
 * sin aprobación, así que 'pendiente' entró a
 * Distribuidora::ESTADOS_SIN_OPERACION.
 *
 * Lo que NO cambia: el admin general sigue pudiendo ver sus datos,
 * aprobarla y rechazarla.
 */
class DistribuidoraPendienteNoOperaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const EMAIL_ADMIN = 'dueno@pendiente.test';

    private const PASSWORD = 'clave-segura-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function pendiente(): Distribuidora
    {
        $distribuidora = app(CrearDistribuidoraAction::class)->ejecutar(
            [
                'nombre_comercial' => 'Zapatería Pendiente',
                'slug' => 'zapateria-pendiente',
                'plan_solicitado_id' => PlanSuscripcion::where('activo', true)->value('id'),
            ],
            [
                'nombre' => 'Dueño Pendiente',
                'email' => self::EMAIL_ADMIN,
                'password' => self::PASSWORD,
            ],
            false,
        );

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $distribuidora;
    }

    private function adminDeLaPendiente(): Usuario
    {
        return Usuario::where('email', self::EMAIL_ADMIN)->firstOrFail();
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // No opera
    // ------------------------------------------------------------------

    public function test_una_pendiente_no_puede_operar(): void
    {
        $this->assertFalse($this->pendiente()->puedeOperar());
    }

    public function test_su_administrador_no_entra_al_panel_y_ve_que_esta_en_revision(): void
    {
        $this->pendiente();

        Livewire::test('auth.login')
            ->set('email', self::EMAIL_ADMIN)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();

        $this->assertSame(
            AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_PENDIENTE,
            app(AccesoPanelWebService::class)->motivoSinAcceso($this->adminDeLaPendiente())
        );
    }

    public function test_el_mensaje_dice_que_sigue(): void
    {
        $this->assertSame(
            'Tu solicitud está en revisión. Te avisaremos por correo cuando sea aprobada.',
            AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_PENDIENTE
        );
    }

    public function test_con_la_sesion_puesta_a_mano_tampoco_llega_al_panel(): void
    {
        $this->pendiente();

        $this->actingAs($this->adminDeLaPendiente())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_PENDIENTE);

        $this->assertGuest();
    }

    public function test_en_la_app_tampoco_entra(): void
    {
        $this->pendiente();

        $respuesta = $this->postJson('/api/auth/login', [
            'email' => self::EMAIL_ADMIN,
            'password' => self::PASSWORD,
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('en revisión', (string) $respuesta->json('errors.email.0'));
    }

    // ------------------------------------------------------------------
    // El admin general sigue trabajando igual
    // ------------------------------------------------------------------

    public function test_el_admin_general_ve_los_datos_de_una_pendiente(): void
    {
        $distribuidora = $this->pendiente();

        $datos = app(DatosSolicitudDistribuidoraAction::class)->ejecutar($distribuidora);

        $this->assertSame('pendiente', $datos['estado']);
        $this->assertSame('Zapatería Pendiente', $datos['nombre_comercial']);
        $this->assertNotEmpty($datos['administradores']);
        $this->assertSame(self::EMAIL_ADMIN, $datos['administradores'][0]['email']);
        // TG-220 (A11): se ve si confirmó su correo.
        $this->assertFalse($datos['administradores'][0]['correo_confirmado']);
        $this->assertNotNull($datos['plan_solicitado']);
    }

    public function test_el_panel_del_admin_general_avisa_si_el_correo_no_esta_confirmado(): void
    {
        $distribuidora = $this->pendiente();

        $this->actingAs($this->adminGeneral());
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $distribuidora->id)
            ->assertSee('Correo sin confirmar');
    }

    public function test_el_filtro_por_estado_viaja_en_la_direccion(): void
    {
        $this->pendiente();

        $this->actingAs($this->adminGeneral());
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        // Es la dirección a la que enlaza el correo de aviso.
        Livewire::withQueryParams(['estado' => 'pendiente'])
            ->test('admin.distribuidoras-index')
            ->assertSet('filtroEstado', 'pendiente')
            ->assertSee('Zapatería Pendiente')
            ->assertDontSee('Calzados Ramírez');
    }

    public function test_un_estado_inventado_en_la_direccion_se_ignora(): void
    {
        $this->pendiente();

        $this->actingAs($this->adminGeneral());
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        // No truena ni filtra por algo que no existe: se ven todas.
        Livewire::withQueryParams(['estado' => 'inventado'])
            ->test('admin.distribuidoras-index')
            ->assertOk()
            ->assertSee('Zapatería Pendiente')
            ->assertSee('Calzados Ramírez');
    }

    public function test_el_correo_de_aviso_enlaza_al_filtro_de_pendientes(): void
    {
        $correo = new \App\Mail\SolicitudAltaDistribuidoraMail(
            nombreDistribuidora: 'Zapatería Pendiente',
            nombreAdministrador: 'Dueño Pendiente',
            emailAdministrador: self::EMAIL_ADMIN,
        );

        $this->assertStringContainsString(
            route('admin.dashboard', ['estado' => 'pendiente']),
            (string) $correo->render()
        );
    }

    public function test_el_admin_general_aprueba_una_pendiente_y_queda_activa(): void
    {
        $distribuidora = $this->pendiente();

        app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora);
        Tenant::olvidarCache();

        $distribuidora->refresh();

        $this->assertSame('activa', $distribuidora->estado);
        $this->assertTrue($distribuidora->puedeOperar());

        // Y ahora sí entra.
        Livewire::test('auth.login')
            ->set('email', self::EMAIL_ADMIN)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_al_aprobar_se_usa_el_plan_que_pidio(): void
    {
        $caro = PlanSuscripcion::where('activo', true)->orderByDesc('precio_base_mensual')->firstOrFail();

        $distribuidora = $this->pendiente();
        $distribuidora->update(['plan_solicitado_id' => $caro->id]);

        app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora->fresh());

        $suscripcion = Tenant::forzar(
            $distribuidora->id,
            fn () => $distribuidora->suscripciones()->latest('id')->firstOrFail()
        );

        $this->assertSame($caro->id, (int) $suscripcion->plan_id);
    }

    public function test_sin_plan_pedido_se_usa_el_de_por_defecto(): void
    {
        $distribuidora = $this->pendiente();
        $distribuidora->update(['plan_solicitado_id' => null]);

        app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora->fresh());

        $suscripcion = Tenant::forzar(
            $distribuidora->id,
            fn () => $distribuidora->suscripciones()->latest('id')->firstOrFail()
        );

        $this->assertSame('Básico', $suscripcion->plan->nombre);
    }

    public function test_el_admin_general_rechaza_una_pendiente(): void
    {
        $distribuidora = $this->pendiente();

        app(RechazarDistribuidoraAction::class)->ejecutar($distribuidora, 'Faltan documentos.');
        Tenant::olvidarCache();

        $this->assertSame('rechazada', $distribuidora->fresh()->estado);
    }
}
