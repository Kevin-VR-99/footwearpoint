<?php

namespace Tests\Feature\Sprint5;

use App\Mail\AvisoVencimientoSuscripcionMail;
use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Models\SuscripcionAviso;
use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Suscripcion\AplicarPagoSuscripcionMercadoPagoAction;
use App\Services\Suscripcion\CrearPagoSuscripcionMercadoPagoAction;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S5-33 a S5-37 y S5-39. Gracia de 5 días, suspensión al sexto solo si se
 * pide, y el pago no levanta una suspensión manual.
 */
class VencimientoSuscripcionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        Mail::fake();
        config(['suscripcion.vencimiento' => 'aviso']);
        Suscripcion::withoutGlobalScopes()->update([
            'estado' => 'activa',
            'fecha_fin' => '2030-06-15',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_dia_del_vencimiento_sigue_activa_y_los_avisos_no_se_repiten(): void
    {
        $this->ponerFin('2026-11-10');

        $this->en('2026-11-07');
        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->assertSame(1, $this->avisos(SuscripcionAviso::POR_VENCER_3));
        $this->assertSame('activa', $this->suscripcion()->estado);

        $this->en('2026-11-09');
        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->assertSame(1, $this->avisos(SuscripcionAviso::POR_VENCER_1));

        $this->en('2026-11-10');
        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->artisan('suscripciones:vencer')->assertSuccessful();

        $this->assertSame('activa', $this->suscripcion()->estado);
        $this->assertSame('activa', $this->distribuidora()->estado);
        $this->assertSame(1, $this->avisos(SuscripcionAviso::VENCE_HOY));
        $this->assertSame(0, Auditoria::withoutGlobalScopes()->where('accion', 'suscripcion.vencida')->count());

        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-aviso-gracia', false)
            ->assertSee('Tu mensualidad vence hoy');
    }

    public function test_la_gracia_marca_vencida_sin_suspender_y_el_cron_es_idempotente(): void
    {
        $this->ponerFin('2026-11-10');
        $reembolsos = Pago::withoutGlobalScopes()->where('tipo', 'like', 'reembolso%')->count();

        $this->en('2026-11-11');
        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();

        $this->assertSame('vencida', $this->suscripcion()->estado);
        $this->assertSame('activa', $this->distribuidora()->estado);
        $this->assertSame(1, $this->avisos(SuscripcionAviso::GRACIA));
        $this->assertSame(1, Auditoria::withoutGlobalScopes()->where('accion', 'suscripcion.vencida')->count());
        $this->assertSame($reembolsos, Pago::withoutGlobalScopes()->where('tipo', 'like', 'reembolso%')->count());

        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Te quedan 5 días de gracia');

        $this->en('2026-11-15');
        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();

        $this->assertSame('activa', $this->distribuidora()->estado);
        $this->assertSame(2, $this->avisos(SuscripcionAviso::GRACIA));
        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail())
            ->get(route('dashboard'))
            ->assertSee('Te queda 1 día de gracia');
    }

    public function test_en_modo_aviso_el_dia_6_no_corta_el_acceso_y_al_activarlo_si_suspende(): void
    {
        $this->ponerFin('2026-11-10');
        $this->en('2026-11-16');

        $this->artisan('suscripciones:vencer', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('activa', $this->suscripcion()->estado);
        $this->assertSame(0, $this->avisos(SuscripcionAviso::SIGUE_VENCIDA));

        $this->artisan('suscripciones:vencer')->assertSuccessful();
        $this->artisan('suscripciones:vencer')->assertSuccessful();

        $this->assertSame('vencida', $this->suscripcion()->estado);
        $this->assertSame('activa', $this->distribuidora()->estado);
        $this->assertNull($this->distribuidora()->motivo_suspension);
        $this->assertSame(1, $this->avisos(SuscripcionAviso::SIGUE_VENCIDA));

        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();
        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();

        $distribuidora = $this->distribuidora();
        $this->assertSame('suspendida', $distribuidora->estado);
        $this->assertSame(Distribuidora::MOTIVO_FALTA_PAGO, $distribuidora->motivo_suspension);
        $this->assertSame(1, $this->avisos(SuscripcionAviso::SUSPENDIDA));
        $this->assertSame(1, Auditoria::withoutGlobalScopes()->where('accion', 'distribuidora.suspendida_falta_pago')->count());

        Mail::assertSent(AvisoVencimientoSuscripcionMail::class, function (AvisoVencimientoSuscripcionMail $correo) {
            return $correo->hasTo(self::ADMIN)
                && $correo->tipo === SuscripcionAviso::SUSPENDIDA
                && str_contains($correo->texto, 'suspendida por falta de pago');
        });
    }

    public function test_pagar_en_gracia_conserva_la_fecha_y_pagar_suspendida_cuenta_desde_hoy(): void
    {
        $this->en('2026-11-13');
        $suscripcion = $this->ponerFin('2026-11-10');
        $suscripcion->update(['estado' => 'vencida']);

        $this->aplicarPago($suscripcion);
        $suscripcion->refresh();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame('2026-12-10', $suscripcion->fecha_fin->toDateString());
        $this->assertSame('activa', $this->distribuidora()->estado);

        $this->en('2026-11-18');
        $suscripcion->update(['estado' => 'vencida', 'fecha_fin' => '2026-11-10']);
        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();
        $this->assertTrue($this->distribuidora()->suspendidaPorFaltaDePago());

        $this->aplicarPago($suscripcion->fresh());
        $suscripcion->refresh();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame('2026-12-18', $suscripcion->fecha_fin->toDateString());
        $this->assertSame('activa', $this->distribuidora()->estado);
        $this->assertNull($this->distribuidora()->motivo_suspension);
        $this->assertSame(1, Auditoria::withoutGlobalScopes()->where('accion', 'distribuidora.reactivada_por_pago')->count());
    }

    public function test_la_regla_de_fechas_incluye_el_31_de_enero(): void
    {
        $this->en('2026-01-31');
        $vigente = new Suscripcion(['estado' => 'activa', 'fecha_fin' => '2026-01-31']);
        $this->assertSame('2026-02-28', CrearPagoSuscripcionMercadoPagoAction::periodoAlPagar($vigente)['hasta']->toDateString());

        $this->en('2026-02-03');
        $gracia = new Suscripcion(['estado' => 'vencida', 'fecha_fin' => '2026-01-31']);
        $this->assertSame('2026-02-28', CrearPagoSuscripcionMercadoPagoAction::periodoAlPagar($gracia)['hasta']->toDateString());

        $this->en('2026-02-08');
        $tarde = new Suscripcion(['estado' => 'vencida', 'fecha_fin' => '2026-01-31']);
        $this->assertSame('2026-03-08', CrearPagoSuscripcionMercadoPagoAction::periodoAlPagar($tarde)['hasta']->toDateString());
    }

    public function test_una_suspension_manual_no_se_levanta_con_el_pago(): void
    {
        Mail::fake();
        $this->en('2026-11-20');
        $suscripcion = $this->ponerFin('2026-11-10');
        $suscripcion->update(['estado' => 'vencida']);
        app(SuspenderDistribuidoraAction::class)->ejecutar($this->distribuidora());

        $this->assertSame(Distribuidora::MOTIVO_MANUAL, $this->distribuidora()->motivo_suspension);
        $this->assertSame(
            MercadoPagoException::DISTRIBUIDORA_NO_ACTIVA,
            CrearPagoSuscripcionMercadoPagoAction::motivoParaNoCobrar($this->distribuidora(), $suscripcion)
        );

        $this->aplicarPago($suscripcion);
        $this->assertSame('suspendida', $this->distribuidora()->estado);
        $this->assertSame(Distribuidora::MOTIVO_MANUAL, $this->distribuidora()->motivo_suspension);
        $this->assertSame(0, Auditoria::withoutGlobalScopes()->where('accion', 'distribuidora.reactivada_por_pago')->count());

        $login = Livewire::test('auth.login')
            ->set('email', self::ADMIN)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertSame(AccesoPanelWebService::MENSAJE_DISTRIBUIDORA_SUSPENDIDA, $login->errors()->first('email'));
    }

    public function test_suspendida_por_falta_de_pago_solo_entra_a_pagar_y_la_app_sigue_cerrada(): void
    {
        $this->ponerFin('2026-11-10');
        $this->en('2026-11-18');
        $this->artisan('suscripciones:vencer', ['--suspender' => true])->assertSuccessful();

        config(['services.mercadopago.access_token' => 'TEST-token-vencimiento']);
        $admin = Usuario::where('email', self::ADMIN)->firstOrFail();
        Livewire::test('auth.login')
            ->set('email', self::ADMIN)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('distribuidora.pago-inicial'));
        $this->actingAs($admin)->get(route('stock.index'))->assertRedirect(route('distribuidora.pago-inicial'));
        $this->actingAs($admin)->get(route('distribuidora.pago-inicial'))
            ->assertOk()
            ->assertSee('Paga tu mensualidad para reactivar')
            ->assertSee('Pagar con Mercado Pago');

        $empleado = Usuario::where('email', self::EMPLEADO)->firstOrFail();
        $this->actingAs($empleado)->get(route('distribuidora.pago-inicial'))
            ->assertOk()
            ->assertSee('El administrador de tu distribuidora debe pagar la mensualidad')
            ->assertDontSee('Pagar con Mercado Pago');

        $this->post(route('logout'));
        $this->assertGuest();
        Tenant::olvidarCache();
        $this->postJson('/api/auth/login', ['email' => self::ADMIN, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->getJson('/api/pedidos')
            ->assertStatus(402)
            ->assertJsonPath('suspension_falta_pago', true);
    }

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function suscripcion(): Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->whereNotNull('fecha_fin')
            ->latest('id')
            ->firstOrFail();
    }

    private function ponerFin(string $fecha): Suscripcion
    {
        $suscripcion = $this->suscripcion();
        $suscripcion->update(['estado' => 'activa', 'fecha_fin' => $fecha]);

        return $suscripcion->fresh();
    }

    private function en(string $fecha): void
    {
        Carbon::setTestNow(Carbon::parse($fecha.' 12:00:00', 'America/Mexico_City'));
    }

    private function avisos(string $tipo): int
    {
        return collect(Mail::sent(AvisoVencimientoSuscripcionMail::class))
            ->filter(fn (AvisoVencimientoSuscripcionMail $correo) => $correo->hasTo(self::ADMIN) && $correo->tipo === $tipo)
            ->count();
    }

    private function aplicarPago(Suscripcion $suscripcion): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token-vencimiento']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response(['id' => 999001], 200),
        ]);

        $pago = Tenant::forzar((int) $suscripcion->distribuidora_id, fn () => Pago::create([
            'distribuidora_id' => $suscripcion->distribuidora_id,
            'suscripcion_id' => $suscripcion->id,
            'folio' => 'PAG-VENC-'.str_replace('.', '', uniqid('', true)),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'mercado_pago',
            'monto' => 100,
            'fecha_pago' => now(),
            'estado' => 'pendiente',
            'proveedor_pago' => 'mercado_pago',
            'registrado_por_staff_id' => null,
        ]));

        $resultado = app(AplicarPagoSuscripcionMercadoPagoAction::class)->ejecutar($pago, [
            'id' => random_int(100000, 999999),
            'status' => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
            'currency_id' => 'MXN',
            'transaction_amount' => 100,
            'collector_id' => 999001,
            'date_approved' => now()->toIso8601String(),
        ]);

        $this->assertSame(AplicarPagoSuscripcionMercadoPagoAction::APLICADO, $resultado);
    }
}
