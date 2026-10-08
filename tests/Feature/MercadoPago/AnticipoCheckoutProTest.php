<?php

namespace Tests\Feature\MercadoPago;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pago\AplicarPagoMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-226 (G7) — El cliente directo paga su anticipo con Checkout Pro contra
 * la cuenta de Mercado Pago de la distribuidora.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests() hace
 * fallar cualquier petición que no esté en Http::fake().
 *
 * Cuentas demo: José (cliente directo con app), el empleado (cobra en
 * mostrador) y María (revendedora). Los pedidos de aquí son de 2 pares.
 */
class AnticipoCheckoutProTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const ADMIN = 'admin@calzadosramirez.test';

    private const MARIA = 'maria.lopez@revendedor.test';

    private const TOKEN = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const CUENTA = '987654321';

    private const URL_PREFERENCIAS = 'https://api.mercadopago.com/checkout/preferences';

    private const PREF_1 = '987654321-aaaaaaaa-1111-2222-3333-444444444444';

    private const PREF_2 = '987654321-bbbbbbbb-1111-2222-3333-444444444444';

    private const PAGO_MP = 555000111;

    /** Lo que regresa la búsqueda de pagos de Mercado Pago (se arma en cada prueba). */
    private array $pagosEnMercadoPago = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();
        // Como en Railway: dominio con https (el root forzado toma el
        // esquema de la petición si no se fuerza también).
        URL::forceRootUrl('https://footwearpoint.test');
        URL::forceScheme('https');

        $this->conectarMercadoPago();
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

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function conectarMercadoPago(array $cambios = []): void
    {
        Tenant::forzar($this->distribuidoraId(), fn () => ConfiguracionDistribuidora::query()->firstOrFail()->update(array_merge([
            'mp_access_token'         => self::TOKEN,
            'mp_public_key'           => 'TEST-public-key',
            'mercado_pago_account_id' => self::CUENTA,
            'mp_conectado_at'         => now(),
        ], $cambios)));
    }

    private function fichaDe(string $email): int
    {
        return (int) ClienteDirecto::withoutGlobalScopes()
            ->where('email', $email)
            ->value('id');
    }

    /** José (o el empleado por Ana) arma un pedido de 2 pares y lo envía. */
    private function pedido(string $quien = self::JOSE, string $cliente = self::JOSE, bool $enviar = true): int
    {
        $this->como($quien);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => $this->fichaDe($cliente),
            'sucursal_id'    => Sucursal::withoutGlobalScopes()->where('es_principal', true)->orderBy('id')->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->firstOrFail();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id'         => $variante->variante_id,
            'cantidad'            => 2,
        ])->assertCreated();

        if ($enviar) {
            $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();
        }

        return $pedidoId;
    }

    private function verPedido(int $pedidoId): array
    {
        $this->como(self::JOSE);

        return $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data');
    }

    private function anticipoPendiente(int $pedidoId): float
    {
        return (float) $this->verPedido($pedidoId)['anticipo_pendiente'];
    }

    /** Mercado Pago simulado: preferencias y búsqueda de pagos. */
    private function fingirMercadoPago(array $preferenciasNuevas = [self::PREF_1]): void
    {
        $secuencia = Http::sequence();
        foreach ($preferenciasNuevas as $id) {
            $secuencia->push(['id' => $id, 'init_point' => $this->initPoint($id)], 201);
        }

        Http::fake([
            self::URL_PREFERENCIAS => $secuencia,
            self::URL_PREFERENCIAS.'/*' => fn (PeticionHttp $peticion) => Http::response([
                'id'         => basename(parse_url($peticion->url(), PHP_URL_PATH)),
                'init_point' => $this->initPoint(basename(parse_url($peticion->url(), PHP_URL_PATH))),
            ], 200),
            'https://api.mercadopago.com/v1/payments/search*' => fn () => Http::response([
                'results' => $this->pagosEnMercadoPago,
                'paging'  => ['total' => count($this->pagosEnMercadoPago)],
            ], 200),
        ]);
    }

    private function initPoint(string $preferencia): string
    {
        return 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.$preferencia;
    }

    private function crearAnticipo(int $pedidoId)
    {
        $this->como(self::JOSE);

        return $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago");
    }

    private function verificar(int $pedidoId)
    {
        $this->como(self::JOSE);

        return $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago/verificar");
    }

    private function pagoMp(int $pagoId): Pago
    {
        return Pago::withoutGlobalScopes()->findOrFail($pagoId);
    }

    private function pagosDelPedido(int $pedidoId)
    {
        return Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->orderBy('id')->get();
    }

    /** Un pago aprobado en Mercado Pago que cuadra con nuestro pago. */
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
            'date_approved'      => '2026-10-07T22:30:00.000-06:00',
        ], $cambios);
    }

    private function pagarEnMostrador(int $pedidoId, float $monto): void
    {
        $this->como(self::EMPLEADO);

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => 'anticipo',
            'metodo' => 'efectivo',
            'monto'  => $monto,
        ])->assertCreated();
    }

    // ---------------------------------------------------------------
    // Crear la preferencia
    // ---------------------------------------------------------------

    public function test_crea_la_preferencia_con_el_token_de_la_distribuidora_y_un_pago_pendiente(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = $this->anticipoPendiente($pedidoId);
        $this->assertGreaterThan(0, $anticipo);
        $this->fingirMercadoPago();

        $respuesta = $this->crearAnticipo($pedidoId);

        $respuesta->assertCreated()
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.preferencia_id', self::PREF_1)
            ->assertJsonPath('data.init_point', $this->initPoint(self::PREF_1))
            ->assertJsonPath('data.reutilizado', false)
            ->assertJsonPath('message', 'Abre el enlace para pagar tu anticipo con Mercado Pago.');
        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());
        $this->assertSame($anticipo, (float) $respuesta->json('data.monto'));

        $pago = $this->pagoMp($respuesta->json('data.pago_id'));
        $this->assertSame('pendiente', $pago->estado);
        $this->assertSame('anticipo', $pago->tipo);
        $this->assertSame('mercado_pago', $pago->metodo);
        $this->assertSame('mercado_pago', $pago->proveedor_pago);
        $this->assertSame(self::PREF_1, $pago->preferencia_externa);
        $this->assertNull($pago->referencia_externa);
        $this->assertNull($pago->registrado_por_staff_id);
        $this->assertSame($respuesta->json('data.folio'), $pago->folio);
        $this->assertSame('FWP-'.$this->distribuidoraId().'-'.$pago->id, $pago->referenciaMercadoPago());

        Http::assertSentCount(1);
        Http::assertSent(function (PeticionHttp $peticion) use ($pago, $anticipo) {
            $datos = $peticion->data();
            $vence = Carbon::parse($datos['expiration_date_to']);
            $retorno = 'https://footwearpoint.test/mercado-pago/retorno';

            return $peticion->url() === self::URL_PREFERENCIAS
                && $peticion->method() === 'POST'
                && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && (float) $datos['items'][0]['unit_price'] === $anticipo
                && $datos['items'][0]['quantity'] === 1
                && $datos['items'][0]['currency_id'] === 'MXN'
                && str_starts_with($datos['items'][0]['title'], 'Anticipo del pedido ')
                && $datos['external_reference'] === $pago->referenciaMercadoPago()
                && $datos['metadata']['pago_id'] === $pago->id
                && $datos['binary_mode'] === true
                && $datos['payment_methods']['excluded_payment_types'] === [['id' => 'ticket'], ['id' => 'atm']]
                && $datos['expires'] === true
                && abs($vence->diffInMinutes(now()->addHours(24))) < 2
                && $datos['back_urls'] === ['success' => $retorno, 'pending' => $retorno, 'failure' => $retorno]
                && $datos['auto_return'] === 'approved'
                // Sin webhook todavía (G9), sin correo del comprador y sin comisión.
                && ! array_key_exists('notification_url', $datos)
                && ! array_key_exists('payer', $datos)
                && ! array_key_exists('marketplace_fee', $datos);
        });

        // Pendiente no es pagado: el anticipo sigue igual.
        $pedido = $this->verPedido($pedidoId);
        $this->assertSame($anticipo, (float) $pedido['anticipo_pendiente']);
        $this->assertSame(0.0, (float) $pedido['pagado']);
        $this->assertTrue($pedido['pago_mercado_pago_pendiente']);
        $this->assertSame('pendiente', $pedido['pagos'][0]['estado']);
        $this->assertSame('mercado_pago', $pedido['pagos'][0]['proveedor_pago']);

        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.preferencia')->sole();
        $this->assertSame(self::PREF_1, $auditoria->datos_nuevos['preferencia']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($auditoria->getAttributes()));
    }

    public function test_sin_https_no_manda_urls_de_regreso_ni_auto_return(): void
    {
        URL::forceRootUrl('http://localhost:8000');
        URL::forceScheme('http');
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertCreated();

        Http::assertSent(fn (PeticionHttp $peticion) => ! array_key_exists('back_urls', $peticion->data())
            && ! array_key_exists('auto_return', $peticion->data()));
    }

    public function test_manda_notification_url_solo_cuando_existe_la_ruta_del_webhook(): void
    {
        Route::post('/mercado-pago/webhook', fn () => response()->noContent())->name('mercado-pago.webhook');
        Route::getRoutes()->refreshNameLookups();

        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertCreated();

        $this->assertSame(
            'https://footwearpoint.test/mercado-pago/webhook',
            Http::recorded()[0][0]->data()['notification_url'] ?? null
        );
    }

    public function test_si_ya_hay_un_enlace_vigente_por_el_mismo_monto_lo_reutiliza(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        $primero = $this->crearAnticipo($pedidoId)->assertCreated();
        $segundo = $this->crearAnticipo($pedidoId);

        $segundo->assertOk()
            ->assertJsonPath('data.pago_id', $primero->json('data.pago_id'))
            ->assertJsonPath('data.reutilizado', true)
            ->assertJsonPath('data.init_point', $this->initPoint(self::PREF_1));

        $this->assertCount(1, $this->pagosDelPedido($pedidoId));
        Http::assertSentCount(2);
        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->method() === 'GET'
            && $peticion->url() === self::URL_PREFERENCIAS.'/'.self::PREF_1);
    }

    public function test_si_cambio_el_monto_reemplaza_el_enlace_y_vence_el_anterior(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = $this->anticipoPendiente($pedidoId);
        $this->fingirMercadoPago([self::PREF_1, self::PREF_2]);

        $primero = $this->crearAnticipo($pedidoId)->assertCreated();
        $this->pagarEnMostrador($pedidoId, 50);

        $segundo = $this->crearAnticipo($pedidoId)->assertCreated()
            ->assertJsonPath('data.preferencia_id', self::PREF_2);
        $this->assertSame(round($anticipo - 50, 2), (float) $segundo->json('data.monto'));

        $this->assertSame('fallido', $this->pagoMp($primero->json('data.pago_id'))->estado);
        $this->assertSame('pendiente', $this->pagoMp($segundo->json('data.pago_id'))->estado);
        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->method() === 'PUT'
            && $peticion->url() === self::URL_PREFERENCIAS.'/'.self::PREF_1
            && $peticion->data()['expires'] === true);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.reemplazado')->count());
    }

    public function test_un_enlace_vencido_se_reemplaza_por_uno_nuevo(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago([self::PREF_1, self::PREF_2]);

        $primero = $this->crearAnticipo($pedidoId)->assertCreated();
        $this->travel(25)->hours();

        $this->crearAnticipo($pedidoId)->assertCreated()->assertJsonPath('data.preferencia_id', self::PREF_2);

        $this->assertSame('fallido', $this->pagoMp($primero->json('data.pago_id'))->estado);
    }

    public function test_si_otra_peticion_esta_preparando_el_pago_pide_esperar(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = $this->anticipoPendiente($pedidoId);
        Tenant::forzar($this->distribuidoraId(), fn () => Pago::create([
            'distribuidora_id' => $this->distribuidoraId(),
            'pedido_id'        => $pedidoId,
            'folio'            => 'PAG-PRUEBA-0001',
            'tipo'             => 'anticipo',
            'direccion'        => 'entrada',
            'metodo'           => 'mercado_pago',
            'monto'            => $anticipo,
            'estado'           => 'pendiente',
        ]));
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)
            ->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::PAGO_EN_PREPARACION]);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Cuándo NO se puede pagar
    // ---------------------------------------------------------------

    public function test_un_pedido_en_borrador_no_se_paga(): void
    {
        $pedidoId = $this->pedido(enviar: false);
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::PEDIDO_BORRADOR]);
        Http::assertNothingSent();
    }

    public function test_un_pedido_cerrado_no_se_paga(): void
    {
        $pedidoId = $this->pedido();
        DB::table('pedidos')->where('id', $pedidoId)->update(['estado' => 'rechazado']);
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::PEDIDO_CERRADO]);
        Http::assertNothingSent();
    }

    public function test_si_el_anticipo_ya_esta_cubierto_no_crea_nada(): void
    {
        $pedidoId = $this->pedido();
        $this->pagarEnMostrador($pedidoId, $this->anticipoPendiente($pedidoId));
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_ANTICIPO_PENDIENTE]);
        $this->assertCount(1, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    public function test_si_la_distribuidora_no_conecto_mercado_pago_lo_explica(): void
    {
        $pedidoId = $this->pedido();
        $this->conectarMercadoPago([
            'mp_access_token' => null, 'mp_public_key' => null, 'mercado_pago_account_id' => null, 'mp_conectado_at' => null,
        ]);
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::NO_ACEPTA_MP]);
        $this->assertCount(0, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    public function test_un_token_que_no_se_puede_descifrar_cuenta_como_no_conectada(): void
    {
        Exceptions::fake();
        $pedidoId = $this->pedido();
        DB::table('configuraciones_distribuidora')
            ->where('distribuidora_id', $this->distribuidoraId())
            ->update(['mp_access_token' => 'no-es-un-valor-cifrado']);
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::NO_ACEPTA_MP]);
        Exceptions::assertReported(DecryptException::class);
        Http::assertNothingSent();
    }

    public function test_una_conexion_vencida_pide_reconectar(): void
    {
        $pedidoId = $this->pedido();
        $this->conectarMercadoPago(['mp_conectado_at' => now()->subDays(181)]);
        $this->fingirMercadoPago();

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::CUENTA_DESCONECTADA]);
        Http::assertNothingSent();
    }

    public function test_solo_el_cliente_directo_dueno_puede_pagar(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $url = "/api/pedidos/{$pedidoId}/anticipo/mercado-pago";

        $this->app['auth']->forgetGuards();
        $this->postJson($url)->assertUnauthorized();

        $this->como(self::EMPLEADO);
        $this->postJson($url)->assertForbidden();
        $this->postJson("{$url}/verificar")->assertForbidden();

        $this->como(self::MARIA);
        $this->postJson($url)->assertForbidden();

        // El pedido de Ana (lo capturó el empleado) no es de José: 404.
        $pedidoDeAna = $this->pedido(self::EMPLEADO, 'ana.garcia@cliente.test');
        $this->crearAnticipo($pedidoDeAna)->assertNotFound();
        $this->verificar($pedidoDeAna)->assertNotFound();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Errores de Mercado Pago
    // ---------------------------------------------------------------

    public function test_si_mercado_pago_rechaza_el_token_pide_reconectar_sin_mostrar_secretos(): void
    {
        Exceptions::fake();
        $pedidoId = $this->pedido();
        Http::fake([self::URL_PREFERENCIAS => Http::response(['error' => 'unauthorized', 'message' => 'invalid access token'], 401)]);

        $respuesta = $this->crearAnticipo($pedidoId);

        $respuesta->assertStatus(422)->assertExactJson(['message' => MercadoPagoException::CUENTA_DESCONECTADA]);
        $this->assertSame('fallido', $this->pagosDelPedido($pedidoId)->sole()->estado);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'HTTP 401')
            && ! str_contains($e->getMessage(), self::TOKEN));
    }

    public function test_si_mercado_pago_no_responde_avisa_y_el_pago_queda_fallido(): void
    {
        Exceptions::fake();
        $pedidoId = $this->pedido();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->crearAnticipo($pedidoId)->assertStatus(503)
            ->assertExactJson(['message' => MercadoPagoException::SIN_RESPUESTA]);
        $this->assertSame('fallido', $this->pagosDelPedido($pedidoId)->sole()->estado);
    }

    public function test_si_mercado_pago_rechaza_la_preferencia_lo_explica(): void
    {
        Exceptions::fake();
        $pedidoId = $this->pedido();
        Http::fake([self::URL_PREFERENCIAS => Http::response(['error' => 'bad_request', 'message' => 'invalid items'], 400)]);

        $this->crearAnticipo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::NO_SE_PUDO_COBRAR]);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'bad_request'));
    }

    // ---------------------------------------------------------------
    // Verificar al regresar de Mercado Pago
    // ---------------------------------------------------------------

    public function test_verificar_aplica_el_pago_aprobado_y_avisa(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = $this->anticipoPendiente($pedidoId);
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $respuesta = $this->verificar($pedidoId);

        $respuesta->assertOk()
            ->assertJsonPath('resultado', 'aplicado')
            ->assertJsonPath('message', 'Recibimos tu anticipo. ¡Gracias!')
            ->assertJsonPath('data.anticipo_pendiente', 0)
            ->assertJsonPath('data.pago_mercado_pago_pendiente', false);
        $this->assertSame($anticipo, (float) $respuesta->json('data.pagado'));

        $pago->refresh();
        $this->assertSame('aplicado', $pago->estado);
        $this->assertSame((string) self::PAGO_MP, $pago->referencia_externa);
        $this->assertSame('2026-10-07 22:30', $pago->fecha_pago->format('Y-m-d H:i'));

        Http::assertSent(fn (PeticionHttp $peticion) => str_starts_with($peticion->url(), 'https://api.mercadopago.com/v1/payments/search')
            && $peticion->data()['external_reference'] === $pago->referenciaMercadoPago()
            && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole();
        $this->assertFalse($auditoria->datos_nuevos['excede_anticipo']);
        $this->assertFalse($auditoria->datos_nuevos['excede_saldo']);

        $jose = Usuario::where('email', self::JOSE)->value('id');
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('usuario_id', $jose)->where('tipo', 'pago_mercado_pago')->exists());
        foreach ([self::ADMIN, self::EMPLEADO] as $staff) {
            $this->assertTrue(Notificacion::withoutGlobalScopes()
                ->where('usuario_id', Usuario::where('email', $staff)->value('id'))
                ->where('tipo', 'pago_mercado_pago')
                ->exists());
        }

        // Verificar otra vez no aplica dos veces ni vuelve a preguntar.
        $enviadas = count(Http::recorded());
        $this->verificar($pedidoId)->assertOk()->assertJsonPath('resultado', 'aplicado');
        $this->assertCount($enviadas, Http::recorded());
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
    }

    public function test_verificar_sin_pagos_en_mercado_pago_sigue_pendiente(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pagoId = $this->crearAnticipo($pedidoId)->json('data.pago_id');

        $this->verificar($pedidoId)->assertOk()
            ->assertJsonPath('resultado', 'pendiente')
            ->assertJsonPath('message', 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.');
        $this->assertSame('pendiente', $this->pagoMp($pagoId)->estado);
    }

    public function test_verificar_un_pago_rechazado_deja_reintentar(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));
        $this->pagosEnMercadoPago = [$this->aprobado($pago, ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason'])];

        $this->verificar($pedidoId)->assertOk()->assertJsonPath('resultado', 'rechazado');
        $this->assertSame('pendiente', $pago->fresh()->estado);
    }

    public function test_verificar_un_enlace_vencido_sin_pago_lo_marca_fallido(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pagoId = $this->crearAnticipo($pedidoId)->json('data.pago_id');
        $this->travel(25)->hours();

        $this->verificar($pedidoId)->assertOk()
            ->assertJsonPath('resultado', 'vencido')
            ->assertJsonPath('message', 'El enlace de pago venció. Genera uno nuevo para pagar tu anticipo.');
        $this->assertSame('fallido', $this->pagoMp($pagoId)->estado);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.vencido')->count());
    }

    public function test_verificar_sin_pago_con_mercado_pago_lo_explica(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        $this->verificar($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_PAGO_POR_CONFIRMAR]);
        Http::assertNothingSent();
    }

    public function test_un_pago_aprobado_que_no_cuadra_no_se_aplica(): void
    {
        Exceptions::fake();
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));

        $this->pagosEnMercadoPago = [$this->aprobado($pago, ['transaction_amount' => 1.0])];
        $this->verificar($pedidoId)->assertOk()->assertJsonPath('resultado', 'pendiente');

        $this->pagosEnMercadoPago = [$this->aprobado($pago, ['collector_id' => 111])];
        $this->verificar($pedidoId)->assertOk()->assertJsonPath('resultado', 'pendiente');

        $this->assertSame('pendiente', $pago->fresh()->estado);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'otro monto'));
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'otra cuenta'));
    }

    public function test_si_el_anticipo_ya_se_cubrio_el_pago_aprobado_se_aplica_y_se_marca(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = $this->anticipoPendiente($pedidoId);
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));
        $this->pagarEnMostrador($pedidoId, $anticipo);
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $this->verificar($pedidoId)->assertOk()->assertJsonPath('resultado', 'aplicado');

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $this->assertTrue(Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole()->datos_nuevos['excede_anticipo']);
    }

    // ---------------------------------------------------------------
    // AplicarPagoMercadoPagoAction (la usará también el webhook de G9)
    // ---------------------------------------------------------------

    public function test_aplicar_es_idempotente_acepta_un_pago_tardio_y_respeta_los_revertidos(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));
        $aplicar = app(AplicarPagoMercadoPagoAction::class);

        // Un enlace reemplazado que igual se pagó: el dinero llegó, se aplica.
        $pago->update(['estado' => 'fallido']);
        $this->assertSame(AplicarPagoMercadoPagoAction::APLICADO, $aplicar->ejecutar($pago, $this->aprobado($pago)));
        $this->assertSame(AplicarPagoMercadoPagoAction::YA_APLICADO, $aplicar->ejecutar($pago, $this->aprobado($pago)));

        $pago->update(['estado' => 'revertido']);
        $this->assertSame(AplicarPagoMercadoPagoAction::NO_APLICA, $aplicar->ejecutar($pago, $this->aprobado($pago)));
        $this->assertSame(AplicarPagoMercadoPagoAction::NO_APLICA, $aplicar->ejecutar($pago, $this->aprobado($pago, ['status' => 'pending'])));
        $this->assertSame('revertido', $pago->fresh()->estado);
    }

    // ---------------------------------------------------------------
    // Panel, página de regreso y anticipo por par
    // ---------------------------------------------------------------

    public function test_el_panel_muestra_el_estado_y_el_personal_puede_verificar(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearAnticipo($pedidoId)->json('data.pago_id'));
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('Mercado Pago')
            ->assertSee('Pendiente')
            ->assertSee('Verificar con Mercado Pago')
            ->call('verificarMercadoPago')
            ->assertSet('mensaje', 'Mercado Pago confirmó el pago. Ya quedó aplicado.')
            ->assertSee('Aplicado')
            ->assertDontSee('Verificar con Mercado Pago');

        $this->assertSame('aplicado', $pago->fresh()->estado);
    }

    public function test_la_pagina_de_regreso_es_publica_y_no_toca_nada(): void
    {
        $this->app['auth']->forgetGuards();
        $antes = Pago::withoutGlobalScopes()->count();

        $this->get('/mercado-pago/retorno?status=approved&payment_id=1&external_reference=FWP-1-1')
            ->assertOk()
            ->assertSee('¡Gracias por tu pago!')
            ->assertSee('Regresa a la app de FootwearPoint');
        $this->get('/mercado-pago/retorno?status=rejected')->assertOk()->assertSee('El pago no se completó');
        $this->get('/mercado-pago/retorno?collection_status=pending')->assertOk()->assertSee('Tu pago está en proceso');

        $this->assertSame($antes, Pago::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_el_anticipo_por_par_nunca_supera_el_precio(): void
    {
        Tenant::forzar($this->distribuidoraId(), fn () => ConfiguracionDistribuidora::query()->firstOrFail()
            ->update(['anticipo_por_producto' => 999999]));

        $pedidoId = $this->pedido(enviar: false);

        $linea = $this->verPedido($pedidoId)['lineas'][0];
        $this->assertSame((float) $linea['subtotal'], (float) $linea['anticipo_requerido']);
        $this->assertSame(
            round((float) Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->total, 2),
            $this->anticipoPendiente($pedidoId)
        );
    }
}
