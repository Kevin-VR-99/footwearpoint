<?php

namespace Tests\Feature\Suscripcion;

use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Pago;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-220 — Cobro antes de acceder: al aprobar una distribuidora ya no se le
 * regala el primer mes. Hasta que lo paga, su personal solo ve la pantalla de
 * pago (y puede cerrar sesión); el primer pago activa un mes desde ese día.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests().
 */
class CobroAntesDeAccederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    /** Token y cuenta de prueba de FootwearPoint (no son reales). */
    private const TOKEN_FWP = 'TEST-2222222222222222-100826-fedcbafedcbafedcbafedcbafedcba98-3748828310';

    private const CUENTA_FWP = '3748828310';

    private const PAGO_MP = 555000888;

    private array $pagos = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();
        URL::forceRootUrl('https://footwearpoint.test');
        URL::forceScheme('https');
        config(['services.mercadopago.access_token' => self::TOKEN_FWP]);

        $this->fingirMercadoPago();
    }

    // ---------------------------------------------------------------

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    /** La distribuidora demo vuelve a "pendiente" y el admin general la aprueba. */
    private function aprobar(?string $plan = 'Pro'): Distribuidora
    {
        Mail::fake();
        $distribuidora = $this->distribuidora();
        Suscripcion::withoutGlobalScopes()->where('distribuidora_id', $distribuidora->id)->delete();
        $distribuidora->update([
            'estado'             => 'pendiente',
            'plan_solicitado_id' => $plan ? PlanSuscripcion::where('nombre', $plan)->value('id') : null,
        ]);

        app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora->fresh());

        return $distribuidora->fresh();
    }

    private function suscripcion(): Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $usuario;
    }

    private function fingirMercadoPago(): void
    {
        Http::fake([
            'https://api.mercadopago.com/*' => function (PeticionHttp $peticion) {
                $ruta = parse_url($peticion->url(), PHP_URL_PATH);

                if ($ruta === '/users/me') {
                    return Http::response(['id' => (int) self::CUENTA_FWP], 200);
                }

                if ($ruta === '/checkout/preferences' && $peticion->method() === 'POST') {
                    return Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-1'], 201);
                }

                if (str_starts_with($ruta, '/checkout/preferences/')) {
                    return Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-1'], 200);
                }

                if (str_starts_with($ruta, '/v1/payments/')) {
                    $id = basename($ruta);

                    return isset($this->pagos[$id])
                        ? Http::response($this->pagos[$id], 200)
                        : Http::response(['message' => 'Payment not found'], 404);
                }

                return Http::response(['message' => 'ruta no esperada en la prueba'], 500);
            },
        ]);
    }

    private function pagarPrimerMes(): Pago
    {
        $this->como(self::ADMIN);

        Livewire::test('distribuidora.pago-inicial')
            ->assertSet('error', '')
            ->call('pagar')
            ->assertRedirect('https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=pref-1');

        return Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------

    public function test_aprobar_crea_la_suscripcion_pendiente_de_pago_sin_mes_gratis(): void
    {
        $distribuidora = $this->aprobar('Pro');

        $this->assertSame('activa', $distribuidora->estado);
        $suscripcion = $this->suscripcion();
        $this->assertSame(Suscripcion::PENDIENTE_PAGO, $suscripcion->estado);
        $this->assertNull($suscripcion->fecha_fin);
        $this->assertSame('Pro', $suscripcion->plan->nombre);
        $this->assertEquals(599, (float) $suscripcion->precio_base_contratado);
        $this->assertSame(0, Suscripcion::withoutGlobalScopes()->where('distribuidora_id', $distribuidora->id)->where('estado', 'activa')->count());
    }

    public function test_el_correo_de_aprobacion_pide_el_pago_y_lista_los_planes(): void
    {
        $this->aprobar('Pro');

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, function (CambioEstadoDistribuidoraMail $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('fue aprobada', $html);
            $this->assertStringContainsString('pago de tu primer mes', $html);
            foreach (['Básico', 'Pro', 'Enterprise'] as $plan) {
                $this->assertStringContainsString($plan, $html);
            }
            $this->assertStringContainsString('$299.00 MXN', $html);
            $this->assertStringContainsString('$599.00 MXN', $html);
            $this->assertStringContainsString('$999.00 MXN', $html);
            $this->assertStringContainsString('$150.00 MXN', $html);
            $this->assertStringContainsString('Pro (tu plan)', $html);
            $this->assertStringNotContainsString('Básico (tu plan)', $html);

            return true;
        });
    }

    public function test_un_plan_inactivo_no_aparece_en_el_correo(): void
    {
        PlanSuscripcion::where('nombre', 'Enterprise')->update(['activo' => false]);

        $this->aprobar('Básico');

        Mail::assertSent(CambioEstadoDistribuidoraMail::class, function (CambioEstadoDistribuidoraMail $mail) {
            $html = $mail->render();
            $this->assertStringNotContainsString('Enterprise', $html);
            $this->assertStringContainsString('Básico (tu plan)', $html);

            return true;
        });
    }

    public function test_al_entrar_se_le_manda_a_la_pantalla_de_pago_y_todo_lo_demas_esta_bloqueado(): void
    {
        $this->aprobar('Pro');
        $this->como(self::ADMIN);

        $this->get(route('dashboard'))->assertRedirect(route('distribuidora.pago-inicial'));
        $this->get(route('distribuidora.configuracion', ['pestana' => 'suscripcion']))->assertRedirect(route('distribuidora.pago-inicial'));
        $this->get(route('distribuidora.catalogo'))->assertRedirect(route('distribuidora.pago-inicial'));
        $this->get(route('distribuidora.pedidos'))->assertRedirect(route('distribuidora.pago-inicial'));

        $this->get(route('distribuidora.pago-inicial'))
            ->assertOk()
            ->assertSee('Paga tu primer mes')
            ->assertSee('Plan Pro')
            ->assertSee('Pagar con Mercado Pago')
            ->assertSee('Cerrar sesión');

        // Cerrar sesión sigue funcionando.
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // La API también (por si usa la app).
        \Laravel\Sanctum\Sanctum::actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());
        $this->getJson('/api/pedidos')->assertStatus(402)->assertJsonPath('pago_inicial_pendiente', true);
        // /api/auth/me no lo bloquea el cobro (al personal la app lo rechaza por su rol, como siempre).
        $this->assertNotSame(402, $this->getJson('/api/auth/me')->status());
    }

    public function test_el_login_lo_lleva_a_la_pantalla_de_pago(): void
    {
        $this->aprobar('Pro');
        $usuario = Usuario::where('email', self::ADMIN)->firstOrFail();
        $usuario->forceFill(['password' => bcrypt('clave-de-prueba-1')])->save();

        Livewire::test('auth.login')
            ->set('email', self::ADMIN)
            ->set('password', 'clave-de-prueba-1')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('distribuidora.pago-inicial'));
    }

    public function test_el_empleado_ve_el_aviso_pero_no_puede_pagar(): void
    {
        $this->aprobar('Pro');
        $this->como(self::EMPLEADO);

        $this->get(route('dashboard'))->assertRedirect(route('distribuidora.pago-inicial'));
        Livewire::test('distribuidora.pago-inicial')
            ->assertSee('El administrador de tu distribuidora debe pagar el primer mes')
            ->assertDontSee('Pagar con Mercado Pago')
            ->call('pagar')
            ->assertForbidden();
    }

    public function test_el_primer_pago_activa_un_mes_desde_el_dia_del_pago_y_abre_el_panel(): void
    {
        $this->aprobar('Pro');
        $this->travel(3)->days();

        $pago = $this->pagarPrimerMes();
        $this->assertSame('pendiente', $pago->estado);
        $this->assertEquals(CobroEsperado::total($this->suscripcion()), (float) $pago->monto);

        $preferencia = collect(Http::recorded())
            ->first(fn ($par) => $par[0]->url() === 'https://api.mercadopago.com/checkout/preferences' && $par[0]->method() === 'POST')[0];
        $this->assertStringContainsString('/distribuidora/pago-inicial', $preferencia['back_urls']['success']);

        $this->pagos[(string) self::PAGO_MP] = [
            'id'                 => self::PAGO_MP,
            'status'             => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
            'currency_id'        => 'MXN',
            'transaction_amount' => (float) $pago->monto,
            'collector_id'       => (int) self::CUENTA_FWP,
            'date_approved'      => now()->toIso8601String(),
        ];

        Livewire::withQueryParams([
            'payment_id'         => (string) self::PAGO_MP,
            'status'             => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
        ])->test('distribuidora.pago-inicial')->assertRedirect(route('dashboard'));

        $suscripcion = $this->suscripcion();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame(today()->toDateString(), $suscripcion->fecha_inicio->toDateString());
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $suscripcion->fecha_fin->toDateString());
        $this->assertSame('aplicado', $pago->fresh()->estado);

        // Ya pagado: el panel normal y la pestaña Suscripción funcionan.
        $this->como(self::ADMIN);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('distribuidora.configuracion', ['pestana' => 'suscripcion']))->assertOk();
        $this->get(route('distribuidora.pago-inicial'))->assertRedirect(route('dashboard'));
        $this->getJson('/api/pedidos')->assertOk();
    }

    public function test_una_distribuidora_activa_con_suscripcion_activa_no_se_bloquea(): void
    {
        $this->como(self::ADMIN);

        $this->assertSame('activa', $this->suscripcion()->estado);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('distribuidora.configuracion', ['pestana' => 'suscripcion']))->assertOk();
        $this->getJson('/api/pedidos')->assertOk();
        $this->get(route('distribuidora.pago-inicial'))->assertRedirect(route('dashboard'));
    }

    public function test_si_el_admin_general_le_asigna_un_plan_se_desbloquea(): void
    {
        $this->aprobar('Pro');

        app(\App\Services\Suscripcion\AsignarSuscripcionAction::class)->ejecutar(
            $this->distribuidora(),
            PlanSuscripcion::where('nombre', 'Básico')->firstOrFail(),
        );

        $this->como(self::ADMIN);
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(0, Suscripcion::withoutGlobalScopes()->where('estado', Suscripcion::PENDIENTE_PAGO)->count());
    }
}

/** El monto del primer mes: precio base + líneas activas por encima de las incluidas. */
final class CobroEsperado
{
    public static function total(Suscripcion $s): float
    {
        $activas = Tenant::forzar((int) $s->distribuidora_id, fn () => DistribuidoraLinea::query()->vigentes()->count());

        return round((float) $s->precio_base_contratado
            + max(0, $activas - (int) $s->lineas_incluidas_contratadas) * (float) $s->precio_linea_extra_contratado, 2);
    }
}
