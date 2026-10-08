<?php

namespace Tests\Feature\MercadoPago;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\WebhookMercadoPago;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\MercadoPago\FirmaWebhookMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pago\AplicarPagoMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-228 (G9) — Confirmación automática del pago con el aviso (webhook) de
 * Mercado Pago: POST /api/webhooks/mercado-pago.
 *
 * Del aviso solo se usan el id del pago y la cuenta del vendedor; lo que
 * decide es GET /v1/payments/{id} con el token de la distribuidora. Cada
 * aviso queda en webhooks_mercado_pago y el repetido no se procesa dos veces.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests().
 */
class WebhookMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const TOKEN = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const CUENTA = '987654321';

    private const CLAVE = 'clave-de-prueba-del-webhook';

    private const URL_PREFERENCIAS = 'https://api.mercadopago.com/checkout/preferences';

    private const URL_WEBHOOK = '/api/webhooks/mercado-pago';

    private const PREF_1 = '987654321-aaaaaaaa-1111-2222-3333-444444444444';

    private const PAGO_MP = 555000333;

    private array $pagosPorId = [];

    /** Si es true, la API de pagos de Mercado Pago responde 500. */
    private bool $mercadoPagoCaido = false;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();
        URL::forceRootUrl('https://footwearpoint.test');
        URL::forceScheme('https');
        config(['services.mercadopago.webhook_secret' => self::CLAVE]);

        Tenant::forzar($this->distribuidoraId(), fn () => ConfiguracionDistribuidora::query()->firstOrFail()->update([
            'mp_access_token'         => self::TOKEN,
            'mp_public_key'           => 'TEST-public-key',
            'mercado_pago_account_id' => self::CUENTA,
            'mp_conectado_at'         => now(),
        ]));

        $this->fingirMercadoPago();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Mercado Pago no manda sesión: el aviso llega sin usuario. */
    private function sinSesion(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function fichaDe(string $email): int
    {
        $usuarioId = Usuario::where('email', $email)->value('id');

        return (int) ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $usuarioId)->value('id');
    }

    /** Un pedido de 2 pares de José, enviado. */
    private function pedido(): int
    {
        $this->como(self::JOSE);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => $this->fichaDe(self::JOSE),
            'sucursal_id'    => Sucursal::withoutGlobalScopes()->where('es_principal', true)->orderBy('id')->value('id'),
        ])->assertCreated()->json('data.id');

        $queVende = Tenant::forzar(
            $this->distribuidoraId(),
            fn () => app(CatalogoVisible::class)->consulta()->pluck('producto_campana.id')
        );

        $variante = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereIn('producto_campana_id', $queVende)
            ->orderBy('id')
            ->firstOrFail();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id'         => $variante->variante_id,
            'cantidad'            => 2,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    /** El pago pendiente del anticipo, como lo deja G7. */
    private function pagoPendiente(): Pago
    {
        $pedidoId = $this->pedido();
        $this->como(self::JOSE);
        $pagoId = $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago")->assertCreated()->json('data.pago_id');

        return Pago::withoutGlobalScopes()->findOrFail($pagoId);
    }

    /** Un pago con Mercado Pago pendiente de un pedido de OTRA distribuidora. */
    private function pagoPendienteDeOtraDistribuidora(): Pago
    {
        $otra = (int) Distribuidora::where('slug', '!=', 'calzados-ramirez')->orderBy('id')->value('id');

        return Tenant::forzar($otra, function () use ($otra) {
            $pedido = Pedido::create([
                'distribuidora_id'       => $otra,
                'sucursal_id'            => Sucursal::query()->where('distribuidora_id', $otra)->orderBy('id')->value('id'),
                'folio'                  => 'PED-OTRA-0001',
                'tipo'                   => 'cliente_directo',
                'estado'                 => 'colocado',
                'subtotal'               => 549,
                'total'                  => 549,
                'fecha_colocacion'       => now(),
                'capturado_por_staff_id' => DistribuidoraStaff::query()->where('distribuidora_id', $otra)->orderBy('id')->value('id'),
            ]);

            return Pago::create([
                'distribuidora_id'    => $otra,
                'pedido_id'           => $pedido->id,
                'folio'               => 'PAG-OTRA-0001',
                'tipo'                => 'anticipo',
                'direccion'           => 'entrada',
                'metodo'              => 'mercado_pago',
                'monto'               => 100,
                'fecha_pago'          => now(),
                'proveedor_pago'      => 'mercado_pago',
                'preferencia_externa' => '111111111-cccccccc-1111-2222-3333-444444444444',
                'estado'              => 'pendiente',
            ]);
        });
    }

    private function fingirMercadoPago(): void
    {
        Http::fake([
            self::URL_PREFERENCIAS => Http::response(['id' => self::PREF_1, 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.self::PREF_1], 201),
            self::URL_PREFERENCIAS.'/*' => Http::response(['id' => self::PREF_1], 200),
            'https://api.mercadopago.com/v1/payments/*' => function (PeticionHttp $peticion) {
                if ($this->mercadoPagoCaido) {
                    return Http::response(['message' => 'internal_error'], 500);
                }

                $id = basename(parse_url($peticion->url(), PHP_URL_PATH));

                return isset($this->pagosPorId[$id])
                    ? Http::response($this->pagosPorId[$id], 200)
                    : Http::response(['message' => 'Payment not found', 'error' => 'not_found', 'status' => 404], 404);
            },
        ]);
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
            'collector_id'       => (int) self::CUENTA,
            'date_approved'      => '2026-10-08T10:30:00.000-06:00',
        ], $cambios);
    }

    /** Lo que Mercado Pago tiene de ese pago (GET /v1/payments/{id}). */
    private function enMercadoPago(array $pagoMp): void
    {
        $this->pagosPorId[(string) $pagoMp['id']] = $pagoMp;
    }

    /** Un webhook (formato nuevo), firmado con la clave, como lo manda Mercado Pago. */
    private function aviso(int|string $pagoMpId = self::PAGO_MP, array $cuerpo = [], ?string $firma = null, string $tipo = 'payment')
    {
        $this->sinSesion();
        $requestId = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
        $ts = '1742505638683';
        $firma ??= 'ts='.$ts.',v1='.hash_hmac('sha256', FirmaWebhookMercadoPago::manifiesto((string) $pagoMpId, $requestId, $ts), self::CLAVE);

        $query = http_build_query(['source_news' => 'webhooks', 'd' => $this->distribuidoraId(), 'data.id' => (string) $pagoMpId, 'type' => $tipo]);

        return $this->withHeaders(['x-signature' => $firma, 'x-request-id' => $requestId])
            ->postJson(self::URL_WEBHOOK.'?'.$query, array_replace_recursive([
                'action'       => 'payment.updated',
                'api_version'  => 'v1',
                'data'         => ['id' => (string) $pagoMpId],
                'date_created' => '2026-10-08T16:30:00Z',
                'id'           => 12345678,
                'live_mode'    => false,
                'type'         => $tipo,
                'user_id'      => (int) self::CUENTA,
            ], $cuerpo));
    }

    /** Un aviso sin firma (el formato viejo IPN: ?topic=payment&id=…). */
    private function ipn(int|string $id = self::PAGO_MP, string $topic = 'payment')
    {
        $this->sinSesion();

        return $this->postJson(self::URL_WEBHOOK.'?'.http_build_query(['topic' => $topic, 'id' => (string) $id, 'd' => $this->distribuidoraId()]), []);
    }

    private function consultasDePagos(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => str_starts_with($par[0]->url(), 'https://api.mercadopago.com/v1/payments/'))
            ->count();
    }

    private function avisosDeJose(): int
    {
        return Notificacion::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->where('tipo', 'pago_mercado_pago')
            ->count();
    }

    // ---------------------------------------------------------------
    // 1. El caso normal
    // ---------------------------------------------------------------

    public function test_un_aviso_firmado_de_un_pago_aprobado_lo_aplica(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk()->assertExactJson(['ok' => true]);

        $pago->refresh();
        $this->assertSame('aplicado', $pago->estado);
        $this->assertSame((string) self::PAGO_MP, $pago->referencia_externa);

        $aviso = WebhookMercadoPago::sole();
        $this->assertSame('payment', $aviso->tipo);
        $this->assertSame((string) self::PAGO_MP, $aviso->recurso_id);
        $this->assertSame($this->distribuidoraId(), (int) $aviso->distribuidora_id);
        $this->assertNotNull($aviso->procesado_at);
        $this->assertNull($aviso->error);
        $this->assertSame((string) self::PAGO_MP, $aviso->payload['query']['data_id']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($aviso->payload));

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->url() === 'https://api.mercadopago.com/v1/payments/'.self::PAGO_MP
            && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
        $this->assertSame(1, $this->avisosDeJose());
    }

    public function test_el_mismo_aviso_repetido_no_se_procesa_dos_veces(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();
        $this->aviso(cuerpo: ['action' => 'payment.created'])->assertOk();
        $this->aviso()->assertOk();

        $this->assertSame(1, WebhookMercadoPago::count());
        $this->assertSame(1, $this->consultasDePagos());
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
        $this->assertSame(1, $this->avisosDeJose());
    }

    public function test_el_ipn_y_el_webhook_del_mismo_pago_caen_en_la_misma_fila(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));

        // El IPN no trae user_id: se usa la pista ?d= y se revisa con la API.
        $this->ipn()->assertOk();
        $this->assertSame('aplicado', $pago->fresh()->estado);

        $this->aviso()->assertOk();

        $this->assertSame(1, WebhookMercadoPago::count());
        $this->assertSame(1, $this->consultasDePagos());
    }

    // ---------------------------------------------------------------
    // 2. La firma
    // ---------------------------------------------------------------

    public function test_una_firma_invalida_responde_401_y_no_hace_nada(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));
        $antes = count(Http::recorded());

        $this->aviso(firma: 'ts=1742505638683,v1='.str_repeat('a', 64))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'La firma del aviso no es válida.']);

        // Firmado para otro pago y cambiado el id: tampoco.
        $firmaDeOtro = 'ts=1,v1='.hash_hmac('sha256', FirmaWebhookMercadoPago::manifiesto('111', 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e', '1'), self::CLAVE);
        $this->aviso(firma: $firmaDeOtro)->assertStatus(401);

        $this->aviso(firma: 'basura')->assertStatus(401);

        $this->assertSame(0, WebhookMercadoPago::count());
        $this->assertCount($antes, Http::recorded());
        $this->assertSame('pendiente', $pago->fresh()->estado);
    }

    public function test_sin_firma_se_procesa_igual_consultando_la_api(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));

        $this->sinSesion();
        $this->postJson(self::URL_WEBHOOK.'?data.id='.self::PAGO_MP.'&type=payment', [
            'type'    => 'payment',
            'data'    => ['id' => (string) self::PAGO_MP],
            'user_id' => (int) self::CUENTA,
        ])->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
    }

    public function test_sin_clave_configurada_se_procesa_igual_y_lo_avisa_en_el_log(): void
    {
        Log::spy();
        config(['services.mercadopago.webhook_secret' => null]);
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje) => str_contains($mensaje, 'MP_WEBHOOK_SECRET'));
    }

    // ---------------------------------------------------------------
    // 3. Estados del pago en Mercado Pago
    // ---------------------------------------------------------------

    public function test_un_pago_en_proceso_deja_el_aviso_abierto_y_el_siguiente_lo_aplica(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago, ['status' => 'in_process', 'status_detail' => 'pending_contingency']));

        $this->aviso(cuerpo: ['action' => 'payment.created'])->assertOk();

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $aviso = WebhookMercadoPago::sole();
        $this->assertNull($aviso->procesado_at);
        $this->assertNull($aviso->error);

        $this->enMercadoPago($this->aprobado($pago));
        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $this->assertNotNull($aviso->fresh()->procesado_at);
    }

    public function test_un_pago_rechazado_no_cambia_nada_y_cierra_el_aviso(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago, ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason']));

        $this->aviso()->assertOk();

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertNotNull(WebhookMercadoPago::sole()->procesado_at);
    }

    public function test_un_pago_aprobado_que_no_cuadra_no_se_aplica(): void
    {
        $pago = $this->pagoPendiente();

        $casos = [
            555000401 => [['transaction_amount' => 1.0], 'otro monto'],
            555000402 => [['collector_id' => 111], 'otra cuenta'],
            555000403 => [['currency_id' => 'USD'], 'otra moneda'],
        ];

        foreach ($casos as $id => [$cambios, $motivo]) {
            $this->enMercadoPago($this->aprobado($pago, ['id' => $id] + $cambios));
            $this->aviso($id)->assertOk();

            $aviso = WebhookMercadoPago::where('recurso_id', (string) $id)->sole();
            $this->assertNotNull($aviso->procesado_at);
            $this->assertStringContainsString($motivo, $aviso->error);
        }

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertNull($pago->fresh()->referencia_externa);
    }

    public function test_una_referencia_de_otra_distribuidora_o_de_un_pago_que_no_existe_no_se_aplica(): void
    {
        $pago = $this->pagoPendiente();
        $otra = (int) Distribuidora::where('slug', '!=', 'calzados-ramirez')->value('id');

        $this->enMercadoPago($this->aprobado($pago, ['id' => 555000501, 'external_reference' => 'FWP-'.$otra.'-'.$pago->id]));
        $this->aviso(555000501)->assertOk();
        $this->assertSame('La referencia del pago es de otra distribuidora.', WebhookMercadoPago::where('recurso_id', '555000501')->value('error'));

        $this->enMercadoPago($this->aprobado($pago, ['id' => 555000502, 'external_reference' => 'FWP-'.$this->distribuidoraId().'-999999']));
        $this->aviso(555000502)->assertOk();
        $this->assertSame('No existe el pago de la referencia.', WebhookMercadoPago::where('recurso_id', '555000502')->value('error'));

        $this->enMercadoPago($this->aprobado($pago, ['id' => 555000503, 'external_reference' => 'pedido-de-otro-sistema']));
        $this->aviso(555000503)->assertOk();
        $this->assertStringStartsWith('Sin manejador', WebhookMercadoPago::where('recurso_id', '555000503')->value('error'));

        $this->assertSame('pendiente', $pago->fresh()->estado);
    }

    /**
     * Pedido por Kevin en la revisión de G9: el pago SÍ existe en Mercado Pago
     * (con el token de esta distribuidora), pero su external_reference apunta
     * a un pago real de OTRA distribuidora. No se aplica ni el ajeno ni el
     * nuestro.
     */
    public function test_un_pago_que_si_existe_pero_apunta_a_un_pago_de_otra_distribuidora_no_se_aplica(): void
    {
        $nuestro = $this->pagoPendiente();
        $ajeno = $this->pagoPendienteDeOtraDistribuidora();
        $otra = (int) $ajeno->distribuidora_id;
        $this->assertNotSame($this->distribuidoraId(), $otra);

        // 1. La referencia dice la verdad: es de la otra distribuidora.
        $this->enMercadoPago($this->aprobado($ajeno, ['id' => 555000601]));
        $this->assertSame('FWP-'.$otra.'-'.$ajeno->id, $this->pagosPorId['555000601']['external_reference']);
        $this->aviso(555000601)->assertOk();

        $aviso = WebhookMercadoPago::where('recurso_id', '555000601')->sole();
        $this->assertSame($this->distribuidoraId(), (int) $aviso->distribuidora_id);
        $this->assertNotNull($aviso->procesado_at);
        $this->assertSame('La referencia del pago es de otra distribuidora.', $aviso->error);

        // 2. La referencia se disfraza con nuestra distribuidora y el id del
        // pago ajeno: con el scope de esta distribuidora no existe.
        $this->enMercadoPago($this->aprobado($ajeno, ['id' => 555000602, 'external_reference' => 'FWP-'.$this->distribuidoraId().'-'.$ajeno->id]));
        $this->aviso(555000602)->assertOk();
        $this->assertSame('No existe el pago de la referencia.', WebhookMercadoPago::where('recurso_id', '555000602')->value('error'));

        // Mercado Pago sí se consultó (el pago existe), pero nada se aplicó.
        $this->assertSame(2, $this->consultasDePagos());
        foreach ([$nuestro, $ajeno] as $pago) {
            $this->assertSame('pendiente', $pago->fresh()->estado);
            $this->assertNull($pago->fresh()->referencia_externa);
        }
        $this->assertSame(0, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('tipo', 'pago_mercado_pago')->count());

        // Y aunque alguien lo intentara con nuestro pago, la referencia no cuadra.
        $this->assertSame(
            AplicarPagoMercadoPagoAction::NO_APLICA,
            app(AplicarPagoMercadoPagoAction::class)->ejecutar($nuestro, $this->pagosPorId['555000601'])
        );
        $this->assertSame('pendiente', $nuestro->fresh()->estado);
    }

    public function test_un_pago_que_mercado_pago_no_encuentra_con_el_token_no_se_aplica(): void
    {
        $pago = $this->pagoPendiente();

        $this->aviso(555000999)->assertOk();

        $this->assertSame('Mercado Pago no encontró el pago con la cuenta de la distribuidora.', WebhookMercadoPago::sole()->error);
        $this->assertSame('pendiente', $pago->fresh()->estado);
    }

    public function test_una_cuenta_que_no_es_de_ninguna_distribuidora_no_consulta_a_mercado_pago(): void
    {
        $this->aviso(cuerpo: ['user_id' => 123123123])->assertOk();

        $aviso = WebhookMercadoPago::sole();
        $this->assertNull($aviso->distribuidora_id);
        $this->assertNotNull($aviso->procesado_at);
        $this->assertSame('La cuenta de Mercado Pago del aviso no está vinculada a ninguna distribuidora.', $aviso->error);
        $this->assertSame(0, $this->consultasDePagos());
    }

    // ---------------------------------------------------------------
    // 4. Junto con G7/G8
    // ---------------------------------------------------------------

    public function test_si_la_pagina_de_regreso_ya_lo_aplico_no_duplica_nada(): void
    {
        $pago = $this->pagoPendiente();
        $pagoMp = $this->aprobado($pago);
        $this->enMercadoPago($pagoMp);
        app(AplicarPagoMercadoPagoAction::class)->ejecutar($pago, $pagoMp);
        $this->assertSame(1, $this->avisosDeJose());

        $this->aviso()->assertOk();

        $aviso = WebhookMercadoPago::sole();
        $this->assertNotNull($aviso->procesado_at);
        $this->assertNull($aviso->error);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
        $this->assertSame(1, $this->avisosDeJose());
        $this->assertSame(1, Pago::withoutGlobalScopes()->where('pedido_id', $pago->pedido_id)->where('estado', 'aplicado')->count());
    }

    public function test_un_enlace_reemplazado_que_si_se_pago_se_aplica(): void
    {
        $pago = $this->pagoPendiente();
        DB::table('pagos')->where('id', $pago->id)->update(['estado' => 'fallido']);
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
    }

    public function test_un_pago_ya_revertido_no_se_vuelve_a_aplicar(): void
    {
        $pago = $this->pagoPendiente();
        DB::table('pagos')->where('id', $pago->id)->update(['estado' => 'revertido']);
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();

        $this->assertSame('revertido', $pago->fresh()->estado);
        $this->assertSame('El pago ya no admite aplicarse.', WebhookMercadoPago::sole()->error);
    }

    public function test_las_preferencias_del_saldo_tambien_mandan_el_aviso_al_webhook(): void
    {
        $pedidoId = $this->pedido();
        $this->como(self::JOSE);
        $anticipo = (float) $this->getJson("/api/pedidos/{$pedidoId}")->json('data.anticipo_pendiente');
        $this->como(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", ['tipo' => 'anticipo', 'metodo' => 'efectivo', 'monto' => $anticipo])->assertCreated();
        DB::table('pedidos')->where('id', $pedidoId)->update(['estado' => 'recibido_distribuidora']);

        $this->como(self::JOSE);
        $this->postJson("/api/pedidos/{$pedidoId}/saldo/mercado-pago")->assertCreated();

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->url() === self::URL_PREFERENCIAS
            && ($peticion->data()['notification_url'] ?? null) === 'https://footwearpoint.test/api/webhooks/mercado-pago?source_news=webhooks&d='.$this->distribuidoraId());
    }

    // ---------------------------------------------------------------
    // 5. Lo que no es un pago de pedido (G10/G11 y otros avisos)
    // ---------------------------------------------------------------

    public function test_otros_tipos_de_aviso_se_guardan_y_no_consultan_nada(): void
    {
        $this->ipn('9876543210', 'merchant_order')->assertOk();
        $this->aviso('2c938084726fca480172750000000000', tipo: 'subscription_preapproval')->assertOk();
        $this->aviso('123', tipo: 'mp-connect')->assertOk();

        $this->assertSame(['merchant_order', 'mp-connect', 'subscription_preapproval'], WebhookMercadoPago::orderBy('tipo')->pluck('tipo')->all());
        foreach (WebhookMercadoPago::all() as $aviso) {
            $this->assertNotNull($aviso->procesado_at);
            $this->assertSame('Sin manejador para este tipo de aviso.', $aviso->error);
        }
        $this->assertSame(0, $this->consultasDePagos());
    }

    public function test_un_pago_que_no_es_de_un_pedido_no_truena(): void
    {
        // Como será la mensualidad de G11: sin pedido.
        $pago = Tenant::forzar($this->distribuidoraId(), fn () => Pago::create([
            'distribuidora_id'    => $this->distribuidoraId(),
            'pedido_id'           => null,
            'folio'               => 'PAG-PRUEBA-G11',
            'tipo'                => 'suscripcion',
            'direccion'           => 'entrada',
            'metodo'              => 'mercado_pago',
            'monto'               => 499,
            'fecha_pago'          => now(),
            'proveedor_pago'      => 'mercado_pago',
            'preferencia_externa' => self::PREF_1,
            'estado'              => 'pendiente',
        ]));
        $this->enMercadoPago($this->aprobado($pago));

        $this->aviso()->assertOk();

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertSame('Sin manejador: el pago no es de un pedido.', WebhookMercadoPago::sole()->error);
    }

    // ---------------------------------------------------------------
    // 6. Fallas
    // ---------------------------------------------------------------

    public function test_si_mercado_pago_no_responde_contesta_500_y_el_reintento_lo_aplica(): void
    {
        $pago = $this->pagoPendiente();
        $this->enMercadoPago($this->aprobado($pago));
        $this->mercadoPagoCaido = true;

        $this->aviso()->assertStatus(500)
            ->assertExactJson(['message' => 'No se pudo procesar el aviso; inténtalo más tarde.']);

        $aviso = WebhookMercadoPago::sole();
        $this->assertNull($aviso->procesado_at);
        $this->assertSame(MercadoPagoException::SIN_RESPUESTA, $aviso->error);
        $this->assertSame('pendiente', $pago->fresh()->estado);

        // Mercado Pago reintenta y ya responde.
        $this->mercadoPagoCaido = false;
        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $this->assertNotNull($aviso->fresh()->procesado_at);
        $this->assertNull($aviso->fresh()->error);
    }

    // ---------------------------------------------------------------
    // 7. Log: los avisos al usuario no son errores del sistema
    // ---------------------------------------------------------------

    public function test_los_422_de_mercado_pago_se_registran_como_info_sin_traza(): void
    {
        $pedidoId = $this->pedido();
        Log::spy();
        $this->como(self::JOSE);

        $this->postJson("/api/pedidos/{$pedidoId}/saldo/mercado-pago")
            ->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SALDO_ANTES_DE_ANTICIPO]);

        Log::shouldHaveReceived('info')->withArgs(fn ($mensaje, $contexto = []) => $mensaje === 'Mercado Pago (aviso al usuario): '.MercadoPagoException::SALDO_ANTES_DE_ANTICIPO
            && $contexto === ['http' => 422]);
        Log::shouldNotHaveReceived('error');
    }

    public function test_si_mercado_pago_no_responde_al_cobrar_queda_como_warning(): void
    {
        Log::spy();

        report(MercadoPagoException::con(MercadoPagoException::SIN_RESPUESTA));

        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => $mensaje === 'Mercado Pago (aviso al usuario): '.MercadoPagoException::SIN_RESPUESTA
            && $contexto === ['http' => 503]);
        Log::shouldNotHaveReceived('error');
    }

    public function test_un_aviso_sin_id_responde_200_y_no_guarda_nada(): void
    {
        $this->sinSesion();

        $this->postJson(self::URL_WEBHOOK, [])->assertOk();
        $this->postJson(self::URL_WEBHOOK.'?type=payment', ['type' => 'payment', 'data' => ['id' => 'no-es-numero']])->assertOk();

        $this->assertSame(0, WebhookMercadoPago::count());
        $this->assertSame(0, $this->consultasDePagos());
    }
}
