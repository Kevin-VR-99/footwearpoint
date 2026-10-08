<?php

namespace Tests\Feature\MercadoPago;

use App\Models\Auditoria;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Models\WebhookMercadoPago;
use App\Services\MercadoPago\FirmaWebhookMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Suscripcion\CrearPagoSuscripcionMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-230 (G11) — La distribuidora paga su mensualidad con Checkout Pro a la
 * cuenta de Mercado Pago de FootwearPoint (MP_ACCESS_TOKEN), desde
 * Configuración > Suscripción (solo admin_distribuidora). Al confirmarse el
 * pago (regreso, botón verificar o aviso de G9), la suscripción se renueva un
 * mes.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests().
 */
class SuscripcionCheckoutProTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    /** Token y cuenta de FootwearPoint (los de la plataforma). */
    private const TOKEN_FWP = 'TEST-2222222222222222-100826-fedcbafedcbafedcbafedcbafedcba98-3748828310';

    private const CUENTA_FWP = '3748828310';

    /** Token y cuenta de la distribuidora (G6): aquí NO se deben usar. */
    private const TOKEN_DIST = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const CUENTA_DIST = '987654321';

    private const CLAVE = 'clave-de-prueba-del-webhook';

    private const PAGO_MP = 555000777;

    /** Pagos que cada token "ve" en Mercado Pago: [token][id] => pago. */
    private array $pagos = [];

    /** Pagos que devuelven las órdenes de la preferencia. */
    private array $pagosDeOrdenes = [];

    private int $preferenciasCreadas = 0;

    /** Si es true, GET /users/me responde 401 (token de FootwearPoint inválido). */
    private bool $tokenFwpInvalido = false;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();
        URL::forceRootUrl('https://footwearpoint.test');
        URL::forceScheme('https');
        config([
            'services.mercadopago.access_token'   => self::TOKEN_FWP,
            'services.mercadopago.webhook_secret' => self::CLAVE,
        ]);

        Tenant::forzar($this->distribuidoraId(), fn () => ConfiguracionDistribuidora::query()->firstOrFail()->update([
            'mp_access_token'         => self::TOKEN_DIST,
            'mp_public_key'           => 'TEST-public-key',
            'mercado_pago_account_id' => self::CUENTA_DIST,
            'mp_conectado_at'         => now(),
        ]));

        // Plan de $299 con 1 línea extra de $150: mensualidad de $449.
        $activas = Tenant::forzar($this->distribuidoraId(), fn () => DistribuidoraLinea::query()->vigentes()->count());
        $this->assertGreaterThanOrEqual(1, $activas);
        $this->suscripcion()->update([
            'estado'                        => 'activa',
            'fecha_inicio'                  => today()->toDateString(),
            'fecha_fin'                     => today()->addMonthNoOverflow()->toDateString(),
            'precio_base_contratado'        => 299,
            'lineas_incluidas_contratadas'  => $activas - 1,
            'precio_linea_extra_contratado' => 150,
            'lineas_extra_contratadas'      => 0,
        ]);

        $this->fingirMercadoPago();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function suscripcion(): Suscripcion
    {
        return Tenant::forzar($this->distribuidoraId(), fn () => Suscripcion::query()->latest('id')->firstOrFail());
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function sinSesion(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function fingirMercadoPago(): void
    {
        Http::fake([
            'https://api.mercadopago.com/*' => function (PeticionHttp $peticion) {
                $ruta = parse_url($peticion->url(), PHP_URL_PATH);
                $token = str_replace('Bearer ', '', $peticion->header('Authorization')[0] ?? '');

                if ($ruta === '/users/me') {
                    return $this->tokenFwpInvalido
                        ? Http::response(['message' => 'invalid access token'], 401)
                        : Http::response(['id' => (int) self::CUENTA_FWP, 'nickname' => 'FOOTWEARPOINT'], 200);
                }

                if ($ruta === '/checkout/preferences' && $peticion->method() === 'POST') {
                    $id = self::CUENTA_FWP.'-pref-'.(++$this->preferenciasCreadas);

                    return Http::response(['id' => $id, 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.$id], 201);
                }

                if (str_starts_with($ruta, '/checkout/preferences/')) {
                    $id = basename($ruta);

                    return Http::response(['id' => $id, 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.$id], 200);
                }

                if ($ruta === '/merchant_orders/search') {
                    return Http::response(['elements' => [['id' => 1, 'payments' => array_map(fn ($id) => ['id' => $id], $this->pagosDeOrdenes)]]], 200);
                }

                if ($ruta === '/v1/payments/search') {
                    return Http::response(['results' => [], 'paging' => ['total' => 0]], 200);
                }

                if (str_starts_with($ruta, '/v1/payments/')) {
                    $id = basename($ruta);

                    return isset($this->pagos[$token][$id])
                        ? Http::response($this->pagos[$token][$id], 200)
                        : Http::response(['message' => 'Payment not found', 'error' => 'not_found', 'status' => 404], 404);
                }

                return Http::response(['message' => 'ruta no esperada en la prueba'], 500);
            },
        ]);
    }

    /** El admin pulsa "Pagar mensualidad con Mercado Pago". */
    private function pagar(): Pago
    {
        $this->como(self::ADMIN);

        Livewire::test('distribuidora.suscripcion')
            ->call('pagar')
            ->assertHasNoErrors()
            ->assertSet('error', '');

        return Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->latest('id')->firstOrFail();
    }

    private function aprobado(Pago $pago, array $cambios = []): array
    {
        return array_merge([
            'id'                 => self::PAGO_MP,
            'status'             => 'approved',
            'status_detail'      => 'accredited',
            'external_reference' => $pago->referenciaMercadoPago(),
            'currency_id'        => 'MXN',
            'transaction_amount' => (float) $pago->monto,
            'collector_id'       => (int) self::CUENTA_FWP,
            'date_approved'      => now()->toIso8601String(),
        ], $cambios);
    }

    private function enMercadoPago(array $pagoMp, string $token = self::TOKEN_FWP): void
    {
        $this->pagos[$token][(string) $pagoMp['id']] = $pagoMp;
    }

    /** Aviso firmado de G9, sin la pista ?d= (las mensualidades no la llevan). */
    private function aviso(string $cuenta = self::CUENTA_FWP, int|string $pagoMpId = self::PAGO_MP)
    {
        $this->sinSesion();
        $requestId = 'cc56a2f1-6aae-46ac-982e-9dcd3581d08e';
        $ts = '1742505638683';
        $firma = 'ts='.$ts.',v1='.hash_hmac('sha256', FirmaWebhookMercadoPago::manifiesto((string) $pagoMpId, $requestId, $ts), self::CLAVE);

        return $this->withHeaders(['x-signature' => $firma, 'x-request-id' => $requestId])
            ->postJson('/api/webhooks/mercado-pago?'.http_build_query(['source_news' => 'webhooks', 'data.id' => (string) $pagoMpId, 'type' => 'payment']), [
                'action'       => 'payment.updated',
                'api_version'  => 'v1',
                'data'         => ['id' => (string) $pagoMpId],
                'date_created' => '2026-10-08T16:30:00Z',
                'id'           => 22345678,
                'live_mode'    => false,
                'type'         => 'payment',
                'user_id'      => (int) $cuenta,
            ]);
    }

    /** Mercado Pago regresa a la pestaña con payment_id y external_reference. */
    private function regresoDeMercadoPago(Pago $pago, int|string $pagoMpId = self::PAGO_MP)
    {
        $this->como(self::ADMIN);

        return Livewire::withQueryParams([
            'pestana'            => 'suscripcion',
            'payment_id'         => (string) $pagoMpId,
            'status'             => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
        ])->test('distribuidora.suscripcion');
    }

    private function avisosAlAdmin(): \Illuminate\Support\Collection
    {
        return Notificacion::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::ADMIN)->value('id'))
            ->where('tipo', 'pago_suscripcion')
            ->get();
    }

    private function preferenciasEnviadas(): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => $par[0]->url() === 'https://api.mercadopago.com/checkout/preferences' && $par[0]->method() === 'POST')
            ->map(fn ($par) => $par[0])
            ->values();
    }

    // ---------------------------------------------------------------
    // 1. Crear el pago
    // ---------------------------------------------------------------

    public function test_el_admin_paga_con_el_token_de_footwearpoint_y_el_monto_incluye_las_lineas_extra(): void
    {
        $this->como(self::ADMIN);

        $componente = Livewire::test('distribuidora.suscripcion')
            ->assertSet('configurado', true)
            ->assertSet('motivo', null)
            ->assertSee('Pagar mensualidad con Mercado Pago')
            ->assertSee('$449.00')
            ->call('pagar');

        $pago = Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->sole();
        $componente->assertRedirect('https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.$pago->preferencia_externa);

        $this->assertSame('pendiente', $pago->estado);
        $this->assertSame('entrada', $pago->direccion);
        $this->assertSame('mercado_pago', $pago->metodo);
        $this->assertNull($pago->pedido_id);
        $this->assertSame($this->suscripcion()->id, (int) $pago->suscripcion_id);
        $this->assertEquals(449, (float) $pago->monto);

        $peticion = $this->preferenciasEnviadas()->sole();
        $this->assertTrue($peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN_FWP));
        $this->assertSame('FWP-'.$this->distribuidoraId().'-'.$pago->id, $peticion['external_reference']);
        $this->assertEquals(449, $peticion['items'][0]['unit_price']);
        $this->assertSame('MXN', $peticion['items'][0]['currency_id']);
        $this->assertSame('suscripcion', $peticion['metadata']['tipo']);
        $this->assertStringContainsString('pestana=suscripcion', $peticion['back_urls']['success']);
        $this->assertSame('https://footwearpoint.test/api/webhooks/mercado-pago?source_news=webhooks', $peticion['notification_url']);

        Http::assertNotSent(fn (PeticionHttp $p) => $p->hasHeader('Authorization', 'Bearer '.self::TOKEN_DIST));
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.preferencia')->count());
    }

    public function test_volver_a_pagar_reutiliza_el_enlace_y_si_cambia_el_monto_lo_reemplaza(): void
    {
        $primero = $this->pagar();
        $segundo = $this->pagar();

        $this->assertSame($primero->id, $segundo->id);
        $this->assertCount(1, $this->preferenciasEnviadas());

        // Una línea extra más: cambia la mensualidad y el enlace viejo se vence.
        $this->suscripcion()->decrement('lineas_incluidas_contratadas');
        $tercero = $this->pagar();

        $this->assertNotSame($primero->id, $tercero->id);
        $this->assertEquals(599, (float) $tercero->monto);
        $this->assertSame('fallido', $primero->fresh()->estado);
        $this->assertCount(2, $this->preferenciasEnviadas());
        Http::assertSent(fn (PeticionHttp $p) => $p->method() === 'PUT'
            && $p->url() === 'https://api.mercadopago.com/checkout/preferences/'.$primero->preferencia_externa);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.reemplazado')->count());
    }

    public function test_solo_el_admin_de_la_distribuidora_puede_pagar(): void
    {
        $this->como(self::EMPLEADO);
        $this->get(route('distribuidora.configuracion', ['pestana' => 'suscripcion']))->assertForbidden();

        Livewire::test('distribuidora.suscripcion')->call('pagar')->assertForbidden();

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->count());
        Http::assertNothingSent();
    }

    public function test_sin_token_de_footwearpoint_el_boton_queda_deshabilitado_con_el_mensaje(): void
    {
        config(['services.mercadopago.access_token' => '']);
        $this->como(self::ADMIN);

        $this->get(route('distribuidora.configuracion', ['pestana' => 'suscripcion']))
            ->assertOk()
            ->assertSeeText('Suscripción')
            ->assertSeeText(MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE)
            ->assertSee('disabled', false);

        Livewire::test('distribuidora.suscripcion')
            ->assertSet('configurado', false)
            ->call('pagar')
            ->assertSet('error', MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE)
            ->assertNoRedirect();

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->count());
        Http::assertNothingSent();
    }

    public function test_explica_por_que_no_se_puede_pagar(): void
    {
        // Ya pagó el siguiente mes: no se adelanta más de un mes.
        $fin = today()->addDays(45);
        $this->suscripcion()->update(['fecha_fin' => $fin->toDateString()]);
        $adelantada = sprintf(MercadoPagoException::SUSCRIPCION_ADELANTADA, $fin->copy()->subMonthNoOverflow()->format('d/m/Y'));

        $this->como(self::ADMIN);
        Livewire::test('distribuidora.suscripcion')
            ->assertSet('motivo', $adelantada)
            ->assertSee($adelantada)
            ->call('pagar')
            ->assertSet('error', $adelantada);

        // Sin suscripción activa o vencida (cancelada, en prueba...).
        $this->suscripcion()->update(['estado' => 'cancelada']);
        Livewire::test('distribuidora.suscripcion')
            ->assertSet('motivo', MercadoPagoException::SIN_SUSCRIPCION_POR_PAGAR)
            ->call('pagar')
            ->assertSet('error', MercadoPagoException::SIN_SUSCRIPCION_POR_PAGAR);

        // Distribuidora que no está activa.
        $this->suscripcion()->update(['estado' => 'activa', 'fecha_fin' => today()->toDateString()]);
        Distribuidora::whereKey($this->distribuidoraId())->update(['estado' => 'suspendida']);

        try {
            app(CrearPagoSuscripcionMercadoPagoAction::class)->ejecutar($this->distribuidoraId());
            $this->fail('Debió rechazar el pago.');
        } catch (MercadoPagoException $e) {
            $this->assertSame(MercadoPagoException::DISTRIBUIDORA_NO_ACTIVA, $e->getMessage());
        }

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('tipo', 'suscripcion')->count());
        $this->assertCount(0, $this->preferenciasEnviadas());
    }

    // ---------------------------------------------------------------
    // 2. Confirmar el pago
    // ---------------------------------------------------------------

    public function test_al_regresar_de_mercado_pago_se_confirma_y_extiende_un_mes(): void
    {
        $finAntes = $this->suscripcion()->fecha_fin->copy();
        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago));

        $this->regresoDeMercadoPago($pago)
            ->assertSee('¡Recibimos el pago de tu mensualidad!')
            ->assertSee($finAntes->copy()->addMonthNoOverflow()->format('d/m/Y'));

        $pago->refresh();
        $this->assertSame('aplicado', $pago->estado);
        $this->assertSame((string) self::PAGO_MP, $pago->referencia_externa);

        $suscripcion = $this->suscripcion();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame($finAntes->copy()->addMonthNoOverflow()->toDateString(), $suscripcion->fecha_fin->toDateString());
        $this->assertSame(1, (int) $suscripcion->lineas_extra_contratadas);

        $this->assertSame(1, Auditoria::where('accion', 'suscripcion.renovada')->count());
        $aplicado = Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole();
        $this->assertFalse($aplicado->datos_nuevos['requiere_revision']);

        $avisos = $this->avisosAlAdmin();
        $this->assertCount(1, $avisos);
        $this->assertSame('Recibimos el pago de tu mensualidad', $avisos[0]->titulo);
        $this->assertStringContainsString($suscripcion->fecha_fin->format('d/m/Y'), $avisos[0]->mensaje);
        $this->assertSame(0, Notificacion::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::EMPLEADO)->value('id'))
            ->where('tipo', 'pago_suscripcion')->count());
        Http::assertNotSent(fn (PeticionHttp $p) => $p->hasHeader('Authorization', 'Bearer '.self::TOKEN_DIST));
    }

    public function test_confirmar_varias_veces_el_mismo_pago_extiende_una_sola_vez(): void
    {
        $finAntes = $this->suscripcion()->fecha_fin->copy();
        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago));

        $this->regresoDeMercadoPago($pago)->assertSee('¡Recibimos el pago de tu mensualidad!');
        $this->regresoDeMercadoPago($pago)->assertSee('¡Recibimos el pago de tu mensualidad!');
        $this->aviso()->assertOk();

        $this->assertSame($finAntes->copy()->addMonthNoOverflow()->toDateString(), $this->suscripcion()->fecha_fin->toDateString());
        $this->assertSame(1, Auditoria::where('accion', 'suscripcion.renovada')->count());
        $this->assertCount(1, $this->avisosAlAdmin());
    }

    public function test_el_boton_verificar_confirma_por_las_ordenes_de_la_preferencia(): void
    {
        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago));
        $this->pagosDeOrdenes = [(string) self::PAGO_MP];

        $this->como(self::ADMIN);
        Livewire::test('distribuidora.suscripcion')
            ->assertSee('Ya pagué, verificar')
            ->call('verificar')
            ->assertSet('error', '')
            ->assertSee('¡Recibimos el pago de tu mensualidad!')
            ->assertDontSee('Ya pagué, verificar');

        $this->assertSame('aplicado', $pago->fresh()->estado);
        Http::assertSent(fn (PeticionHttp $p) => str_starts_with($p->url(), 'https://api.mercadopago.com/merchant_orders/search')
            && $p->hasHeader('Authorization', 'Bearer '.self::TOKEN_FWP));
    }

    public function test_un_pago_que_no_cuadra_no_se_aplica(): void
    {
        $pago = $this->pagar();

        // Otro monto.
        $this->enMercadoPago($this->aprobado($pago, ['transaction_amount' => 1.00]));
        $this->regresoDeMercadoPago($pago)
            ->assertSee('no coincide con tu mensualidad');
        $this->assertSame('pendiente', $pago->fresh()->estado);

        // El dinero llegó a la cuenta de la distribuidora, no a FootwearPoint.
        $this->enMercadoPago($this->aprobado($pago, ['id' => 555000778, 'collector_id' => (int) self::CUENTA_DIST]));
        $this->regresoDeMercadoPago($pago, 555000778)
            ->assertSee('no coincide con tu mensualidad');

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertSame(0, Auditoria::where('accion', 'suscripcion.renovada')->count());
        $this->assertCount(0, $this->avisosAlAdmin());
    }

    // ---------------------------------------------------------------
    // 3. El aviso de Mercado Pago (G9)
    // ---------------------------------------------------------------

    public function test_el_aviso_de_la_cuenta_de_footwearpoint_aplica_la_mensualidad(): void
    {
        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $aviso = WebhookMercadoPago::sole();
        $this->assertNull($aviso->error);
        $this->assertNotNull($aviso->procesado_at);
        $this->assertSame($this->distribuidoraId(), (int) $aviso->distribuidora_id);
        Http::assertSent(fn (PeticionHttp $p) => $p->url() === 'https://api.mercadopago.com/v1/payments/'.self::PAGO_MP
            && $p->hasHeader('Authorization', 'Bearer '.self::TOKEN_FWP));
        $this->assertCount(1, $this->avisosAlAdmin());
    }

    public function test_una_mensualidad_que_llega_por_la_cuenta_de_la_distribuidora_no_se_aplica(): void
    {
        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago, ['collector_id' => (int) self::CUENTA_DIST]), self::TOKEN_DIST);

        $this->aviso(self::CUENTA_DIST)->assertOk();

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertSame('Sin manejador: el pago no es de un pedido.', WebhookMercadoPago::sole()->error);
        $this->assertSame(0, Auditoria::where('accion', 'suscripcion.renovada')->count());
    }

    public function test_con_token_de_footwearpoint_los_avisos_de_las_distribuidoras_siguen_su_camino(): void
    {
        // La cuenta de FootwearPoint se pregunta una vez y queda en caché.
        $this->aviso(self::CUENTA_DIST, 111)->assertOk();
        $this->aviso(self::CUENTA_DIST, 222)->assertOk();

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($par) => str_ends_with($par[0]->url(), '/users/me'))->count());
        $this->assertSame(
            ['Mercado Pago no encontró el pago con la cuenta de la distribuidora.'],
            WebhookMercadoPago::orderBy('id')->pluck('error')->unique()->values()->all()
        );

        // Si el token de FootwearPoint deja de servir, los pedidos no se frenan.
        cache()->flush();
        $this->tokenFwpInvalido = true;
        $this->aviso(self::CUENTA_DIST, 333)->assertOk();
        $this->assertSame('Mercado Pago no encontró el pago con la cuenta de la distribuidora.', WebhookMercadoPago::latest('id')->first()->error);
    }

    // ---------------------------------------------------------------
    // 4. Suscripción vencida o cancelada
    // ---------------------------------------------------------------

    public function test_una_suscripcion_vencida_se_reactiva_por_un_mes_desde_hoy(): void
    {
        $this->suscripcion()->update(['estado' => 'vencida', 'fecha_fin' => today()->subDays(5)->toDateString()]);

        $this->como(self::ADMIN);
        Livewire::test('distribuidora.suscripcion')
            ->assertSee('Vencida')
            ->assertSet('pagadoHastaAlPagar', today()->addMonthNoOverflow()->format('d/m/Y'));

        $pago = $this->pagar();
        $this->enMercadoPago($this->aprobado($pago));
        $this->aviso()->assertOk();

        $suscripcion = $this->suscripcion();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $suscripcion->fecha_fin->toDateString());
    }

    public function test_si_la_suscripcion_se_cancelo_antes_de_confirmarse_el_pago_se_registra_sin_renovar(): void
    {
        $pago = $this->pagar();
        $finAntes = $this->suscripcion()->fecha_fin->toDateString();
        $this->suscripcion()->update(['estado' => 'cancelada']);
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $suscripcion = $this->suscripcion();
        $this->assertSame('cancelada', $suscripcion->estado);
        $this->assertSame($finAntes, $suscripcion->fecha_fin->toDateString());
        $this->assertTrue(Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole()->datos_nuevos['requiere_revision']);
        $this->assertSame(0, Auditoria::where('accion', 'suscripcion.renovada')->count());
        $this->assertStringContainsString('El equipo de FootwearPoint revisará', $this->avisosAlAdmin()->sole()->mensaje);
    }
}
