<?php

namespace Tests\Feature\MercadoPago;

use App\Http\Controllers\Api\PagoMercadoPagoController;
use App\Models\Auditoria;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\WebhookMercadoPago;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\MercadoPago\FirmaWebhookMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pago\CrearPagoMayoristaMercadoPagoAction;
use App\Services\Pago\CrearPagoPedidoMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-229 (G10) — El cliente mayorista (revendedor en el código) paga su
 * pedido con Checkout Pro contra la cuenta de Mercado Pago de la
 * distribuidora: POST /api/pedidos/{id}/total/mercado-pago.
 *
 * Reglas: pago tipo 'total_revendedor' por TODO lo que falta (sin pagos
 * parciales), desde que el pedido se envía hasta que está listo para entrega,
 * al precio que ya tiene el pedido. Un solo enlace vivo por pedido. El
 * mostrador sigue cobrando a mano. En pantalla se dice "cliente mayorista".
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests().
 */
class PagoMayoristaCheckoutProTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    private const ROBERTO = 'roberto.garcia@revendedor.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const ADMIN = 'admin@calzadosramirez.test';

    private const TOKEN = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const CUENTA = '987654321';

    private const CLAVE = 'clave-de-prueba-del-webhook';

    private const URL_PREFERENCIAS = 'https://api.mercadopago.com/checkout/preferences';

    private const PREF_1 = '987654321-aaaaaaaa-1111-2222-3333-444444444444';

    private const PREF_2 = '987654321-bbbbbbbb-1111-2222-3333-444444444444';

    private const PAGO_MP = 555000444;

    private array $pagosEnMercadoPago = [];

    private array $pagosPorId = [];

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

    private function enElPanel(string $email): void
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

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function afiliacionDe(string $email): int
    {
        return (int) RevendedorDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidoraId())
            ->whereHas('revendedor', fn ($consulta) => $consulta->where('email', $email))
            ->value('id');
    }

    /**
     * Un pedido de 2 pares de María, que lo arma ella misma desde la app (el
     * servidor pone el tipo, el dueño y el precio de cliente mayorista). Con
     * $quien = EMPLEADO lo captura el personal por $cliente.
     */
    private function pedido(bool $enviar = true, string $quien = self::MARIA, string $cliente = self::MARIA): int
    {
        $this->como($quien);

        $datos = $quien === self::EMPLEADO ? [
            'tipo'           => 'revendedor',
            'propietario_id' => $this->afiliacionDe($cliente),
            'sucursal_id'    => Sucursal::withoutGlobalScopes()->where('es_principal', true)->orderBy('id')->value('id'),
        ] : [];

        $pedidoId = (int) $this->postJson('/api/pedidos', $datos)->assertCreated()->json('data.id');

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

        if ($enviar) {
            $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();
        }

        return $pedidoId;
    }

    private function cambiarEstado(int $pedidoId, string $estado): void
    {
        DB::table('pedidos')->where('id', $pedidoId)->update(['estado' => $estado]);
    }

    private function verPedido(int $pedidoId, string $quien = self::MARIA): array
    {
        $this->como($quien);

        return $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data');
    }

    /** El mostrador cobra a mano ("Saldo / Total"). */
    private function pagarEnMostrador(int $pedidoId, float $monto): void
    {
        $this->como(self::EMPLEADO);

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => 'saldo_pedido',
            'metodo' => 'efectivo',
            'monto'  => $monto,
        ])->assertCreated();
    }

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
            'https://api.mercadopago.com/v1/payments/*' => function (PeticionHttp $peticion) {
                $id = basename(parse_url($peticion->url(), PHP_URL_PATH));

                return isset($this->pagosPorId[$id])
                    ? Http::response($this->pagosPorId[$id], 200)
                    : Http::response(['message' => 'Payment not found', 'error' => 'not_found', 'status' => 404], 404);
            },
            'https://api.mercadopago.com/merchant_orders/search*' => fn () => Http::response(['elements' => [], 'total' => 0], 200),
        ]);
    }

    private function initPoint(string $preferencia): string
    {
        return 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id='.$preferencia;
    }

    private function crearPago(int $pedidoId)
    {
        $this->como(self::MARIA);

        return $this->postJson("/api/pedidos/{$pedidoId}/total/mercado-pago");
    }

    private function verificarPago(int $pedidoId, array $datos = [])
    {
        $this->como(self::MARIA);

        return $this->postJson("/api/pedidos/{$pedidoId}/total/mercado-pago/verificar", $datos);
    }

    private function pagoMp(int $pagoId): Pago
    {
        return Pago::withoutGlobalScopes()->findOrFail($pagoId);
    }

    private function pagosDelPedido(int $pedidoId)
    {
        return Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->orderBy('id')->get();
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

    /** Un webhook firmado (formato nuevo), como lo manda Mercado Pago. */
    private function aviso(int|string $pagoMpId = self::PAGO_MP)
    {
        $this->sinSesion();
        $requestId = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
        $ts = '1742505638683';
        $firma = 'ts='.$ts.',v1='.hash_hmac('sha256', FirmaWebhookMercadoPago::manifiesto((string) $pagoMpId, $requestId, $ts), self::CLAVE);

        $query = http_build_query(['source_news' => 'webhooks', 'd' => $this->distribuidoraId(), 'data.id' => (string) $pagoMpId, 'type' => 'payment']);

        return $this->withHeaders(['x-signature' => $firma, 'x-request-id' => $requestId])
            ->postJson('/api/webhooks/mercado-pago?'.$query, [
                'action'      => 'payment.updated',
                'api_version' => 'v1',
                'data'        => ['id' => (string) $pagoMpId],
                'id'          => 12345679,
                'live_mode'   => false,
                'type'        => 'payment',
                'user_id'     => (int) self::CUENTA,
            ]);
    }

    private function avisosDe(string $email)
    {
        return Notificacion::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', $email)->value('id'))
            ->where('tipo', 'pago_mercado_pago')
            ->get();
    }

    // ---------------------------------------------------------------
    // 1. Crear la preferencia
    // ---------------------------------------------------------------

    public function test_crea_la_preferencia_por_todo_lo_que_falta_con_el_token_de_la_distribuidora(): void
    {
        $pedidoId = $this->pedido();
        $antes = $this->verPedido($pedidoId);
        $total = (float) $antes['total'];
        $this->assertGreaterThan(0, $total);
        $this->assertSame('colocado', $antes['estado']);
        $this->assertSame($total, (float) $antes['saldo']);
        $this->assertEquals(0, $antes['anticipo_requerido']);
        $this->assertTrue($antes['puede_pagar_total_mercado_pago']);
        $this->assertFalse($antes['puede_pagar_saldo_mercado_pago']);
        $this->fingirMercadoPago();

        $respuesta = $this->crearPago($pedidoId);

        $respuesta->assertCreated()
            ->assertJsonPath('data.tipo', 'total_revendedor')
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.preferencia_id', self::PREF_1)
            ->assertJsonPath('data.init_point', $this->initPoint(self::PREF_1))
            ->assertJsonPath('data.reutilizado', false)
            ->assertJsonPath('message', 'Abre el enlace para pagar tu pedido con Mercado Pago.');
        // Al precio que ya tiene el pedido (el de cliente mayorista).
        $this->assertSame($total, (float) $respuesta->json('data.monto'));
        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());

        $pago = $this->pagoMp($respuesta->json('data.pago_id'));
        $this->assertSame('total_revendedor', $pago->tipo);
        $this->assertSame('pendiente', $pago->estado);
        $this->assertSame('mercado_pago', $pago->metodo);
        $this->assertSame('entrada', $pago->direccion);
        $this->assertSame(self::PREF_1, $pago->preferencia_externa);
        $this->assertNull($pago->registrado_por_staff_id);

        Http::assertSentCount(1);
        Http::assertSent(function (PeticionHttp $peticion) use ($pago, $total) {
            $datos = $peticion->data();

            return $peticion->url() === self::URL_PREFERENCIAS
                && $peticion->method() === 'POST'
                && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && (float) $datos['items'][0]['unit_price'] === $total
                && $datos['items'][0]['quantity'] === 1
                && str_starts_with($datos['items'][0]['title'], 'Pago del pedido ')
                && $datos['external_reference'] === $pago->referenciaMercadoPago()
                && $datos['metadata']['tipo'] === 'total_revendedor'
                && $datos['binary_mode'] === true
                && $datos['expires'] === true
                && $datos['auto_return'] === 'approved'
                && $datos['notification_url'] === 'https://footwearpoint.test/api/webhooks/mercado-pago?source_news=webhooks&d='.$this->distribuidoraId();
        });

        // Pendiente no es pagado.
        $despues = $this->verPedido($pedidoId);
        $this->assertSame($total, (float) $despues['saldo']);
        $this->assertTrue($despues['pago_mercado_pago_pendiente']);
        $this->assertSame('total_revendedor', $despues['pago_mercado_pago_pendiente_tipo']);

        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.preferencia')->sole();
        $this->assertSame('total_revendedor', $auditoria->datos_nuevos['tipo']);
    }

    // ---------------------------------------------------------------
    // 2. Un solo enlace vivo; el mostrador no se bloquea
    // ---------------------------------------------------------------

    public function test_reutiliza_el_enlace_vigente_y_si_el_mostrador_cobra_una_parte_lo_reemplaza_por_lo_que_falta(): void
    {
        $pedidoId = $this->pedido();
        $saldo = (float) $this->verPedido($pedidoId)['saldo'];
        $this->fingirMercadoPago([self::PREF_1, self::PREF_2]);

        $primero = $this->crearPago($pedidoId)->assertCreated();
        $this->crearPago($pedidoId)->assertOk()
            ->assertJsonPath('data.pago_id', $primero->json('data.pago_id'))
            ->assertJsonPath('data.reutilizado', true);

        // El mostrador cobra una parte a mano: no se bloquea por el enlace.
        $this->pagarEnMostrador($pedidoId, 100);

        $segundo = $this->crearPago($pedidoId)->assertCreated()
            ->assertJsonPath('data.preferencia_id', self::PREF_2);
        $this->assertSame(round($saldo - 100, 2), (float) $segundo->json('data.monto'));

        $this->assertSame('fallido', $this->pagoMp($primero->json('data.pago_id'))->estado);
        $this->assertSame('pendiente', $this->pagoMp($segundo->json('data.pago_id'))->estado);
        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->method() === 'PUT'
            && $peticion->url() === self::URL_PREFERENCIAS.'/'.self::PREF_1
            && $peticion->data()['expires'] === true);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.reemplazado')->count());
        $this->assertSame(1, $this->pagosDelPedido($pedidoId)->where('estado', 'pendiente')->count());
    }

    // ---------------------------------------------------------------
    // 3, 4 y 5. Cuándo se puede pagar
    // ---------------------------------------------------------------

    public function test_se_puede_pagar_desde_que_se_envia_hasta_que_esta_listo_para_entrega(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        foreach (CrearPagoPedidoMercadoPagoAction::ESTADOS_PARA_MAYORISTA as $i => $estado) {
            $this->cambiarEstado($pedidoId, $estado);
            $this->assertTrue($this->verPedido($pedidoId)['puede_pagar_total_mercado_pago'], $estado);

            $this->crearPago($pedidoId)
                ->assertStatus($i === 0 ? 201 : 200)
                ->assertJsonPath('data.tipo', 'total_revendedor');
        }

        $this->assertSame([
            'colocado', 'en_revision', 'confirmado', 'parcialmente_disponible', 'incluido_en_ciclo',
            'solicitado_fabrica', 'en_transito', 'recibido_distribuidora', 'listo_entrega',
        ], CrearPagoPedidoMercadoPagoAction::ESTADOS_PARA_MAYORISTA);
        $this->assertCount(1, $this->pagosDelPedido($pedidoId));
    }

    public function test_en_borrador_o_con_el_pedido_entregado_o_cerrado_no_crea_nada(): void
    {
        $borrador = $this->pedido(enviar: false);
        $this->assertFalse($this->verPedido($borrador)['puede_pagar_total_mercado_pago']);
        $this->fingirMercadoPago();

        $this->crearPago($borrador)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::ENVIA_ANTES_DE_PAGAR]);

        $pedidoId = $this->pedido();
        foreach (['entregado', 'rechazado', 'descartado', 'no_surtido', 'vencido_recoleccion'] as $estado) {
            $this->cambiarEstado($pedidoId, $estado);
            $this->assertFalse($this->verPedido($pedidoId)['puede_pagar_total_mercado_pago'], $estado);
            $this->crearPago($pedidoId)->assertStatus(422)
                ->assertExactJson(['message' => MercadoPagoException::PEDIDO_CERRADO]);
        }

        $this->assertCount(0, $this->pagosDelPedido($borrador));
        $this->assertCount(0, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    public function test_sin_saldo_pendiente_no_crea_nada(): void
    {
        $pedidoId = $this->pedido();
        $this->pagarEnMostrador($pedidoId, $this->verPedido($pedidoId)['saldo']);
        $this->assertFalse($this->verPedido($pedidoId)['puede_pagar_total_mercado_pago']);
        $this->fingirMercadoPago();

        $this->crearPago($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_SALDO_PENDIENTE]);

        $this->assertCount(1, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // 6 y 7. Quién puede pagar
    // ---------------------------------------------------------------

    public function test_solo_el_cliente_mayorista_dueno_puede_pagar_su_pedido(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $url = "/api/pedidos/{$pedidoId}/total/mercado-pago";

        $this->sinSesion();
        $this->postJson($url)->assertUnauthorized();

        $this->como(self::EMPLEADO);
        $this->postJson($url)->assertForbidden();
        $this->postJson("{$url}/verificar")->assertForbidden();

        $this->como(self::JOSE);
        $this->postJson($url)->assertForbidden();
        $this->postJson("{$url}/verificar")->assertForbidden();

        // El pedido de Roberto (lo capturó el empleado) no es de María: 404.
        $deRoberto = $this->pedido(quien: self::EMPLEADO, cliente: self::ROBERTO);
        $this->assertSame('revendedor', Pedido::withoutGlobalScopes()->find($deRoberto)->tipo);
        $this->crearPago($deRoberto)->assertNotFound();
        $this->verificarPago($deRoberto)->assertNotFound();

        Http::assertNothingSent();
        $this->assertCount(0, Pago::withoutGlobalScopes()->where('metodo', 'mercado_pago')->get());
    }

    public function test_el_cliente_mayorista_no_usa_las_rutas_del_cliente_directo_ni_al_reves(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();

        $this->como(self::MARIA);
        $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago")->assertForbidden();
        $this->postJson("/api/pedidos/{$pedidoId}/saldo/mercado-pago")->assertForbidden();

        // Un pedido de cliente directo no se cobra como de cliente mayorista.
        $this->como(self::JOSE);
        $pedidoDeJose = (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');
        $deJose = Pedido::withoutGlobalScopes()->findOrFail($pedidoDeJose);
        $this->assertSame('cliente_directo', $deJose->tipo);
        $this->cambiarEstado($pedidoDeJose, 'colocado');
        $this->assertFalse($this->verPedido($pedidoDeJose, self::JOSE)['puede_pagar_total_mercado_pago']);

        try {
            Tenant::forzar($this->distribuidoraId(), fn () => app(CrearPagoMayoristaMercadoPagoAction::class)->ejecutar($deJose->fresh()));
            $this->fail('Debió rechazar el pedido de cliente directo.');
        } catch (MercadoPagoException $e) {
            $this->assertSame(MercadoPagoException::SOLO_CLIENTE_MAYORISTA, $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertCount(0, Pago::withoutGlobalScopes()->where('metodo', 'mercado_pago')->get());
    }

    // ---------------------------------------------------------------
    // 8 y 9. Verificar
    // ---------------------------------------------------------------

    public function test_verificar_aplica_el_pago_y_el_pedido_ya_se_puede_entregar(): void
    {
        $pedidoId = $this->pedido();
        $this->cambiarEstado($pedidoId, 'listo_entrega');
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearPago($pedidoId)->json('data.pago_id'));
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $respuesta = $this->verificarPago($pedidoId);

        $respuesta->assertOk()
            ->assertJsonPath('resultado', 'aplicado')
            ->assertJsonPath('message', 'Recibimos el pago de tu pedido. ¡Gracias!')
            ->assertJsonPath('data.saldo', 0)
            ->assertJsonPath('data.pago_mercado_pago_pendiente', false)
            ->assertJsonPath('data.pago_mercado_pago_pendiente_tipo', null)
            ->assertJsonPath('data.puede_pagar_total_mercado_pago', false)
            ->assertJsonPath('data.propietario.nombre', 'María López');

        $pago->refresh();
        $this->assertSame('aplicado', $pago->estado);
        $this->assertSame((string) self::PAGO_MP, $pago->referencia_externa);

        // Otra vez: no duplica nada.
        $this->verificarPago($pedidoId)->assertOk()->assertJsonPath('resultado', 'aplicado');
        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole();
        $this->assertFalse($auditoria->datos_nuevos['excede_saldo']);

        // Con el saldo en cero, el mostrador ya puede entregar.
        $this->enElPanel(self::EMPLEADO);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->call('marcarEntregado')
            ->assertSet('errorMsg', '')
            ->assertSet('mensaje', 'Pedido entregado.');
        $this->assertSame('entregado', Pedido::withoutGlobalScopes()->find($pedidoId)->estado);
    }

    public function test_un_pago_que_no_cuadra_no_se_aplica_ni_en_la_app_ni_en_el_panel(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearPago($pedidoId)->json('data.pago_id'));

        // Otro monto.
        $this->pagosPorId = ['777' => $this->aprobado($pago, ['id' => 777, 'transaction_amount' => 1.0])];
        $this->verificarPago($pedidoId, ['payment_id' => '777'])->assertOk()
            ->assertJsonPath('resultado', 'no_cuadra')
            ->assertJsonPath('message', 'Mercado Pago tiene un pago que no coincide con este pedido, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo.');

        // Otra cuenta.
        $this->pagosPorId = ['778' => $this->aprobado($pago, ['id' => 778, 'collector_id' => 111])];
        $this->verificarPago($pedidoId, ['payment_id' => '778'])->assertOk()
            ->assertJsonPath('resultado', 'no_cuadra');

        $this->enElPanel(self::EMPLEADO);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoMpId', '777')
            ->call('verificarMercadoPago')
            ->assertSet('mensaje', 'Mercado Pago tiene un pago que no coincide con este pago (referencia, monto, moneda o cuenta) y no se aplicó. Revísalo en tu cuenta de Mercado Pago antes de registrar algo a mano.');

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertSame(0, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
    }

    // ---------------------------------------------------------------
    // 10 y 11. El aviso de Mercado Pago (G9) y la página de regreso
    // ---------------------------------------------------------------

    public function test_el_aviso_de_mercado_pago_aplica_el_pago_del_cliente_mayorista(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearPago($pedidoId)->json('data.pago_id'));
        $this->pagosPorId = [(string) self::PAGO_MP => $this->aprobado($pago)];

        $this->aviso()->assertOk()->assertExactJson(['ok' => true]);
        $this->aviso()->assertOk();

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $aviso = WebhookMercadoPago::sole();
        $this->assertNotNull($aviso->procesado_at);
        $this->assertNull($aviso->error);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
        $this->assertCount(1, $this->avisosDe(self::MARIA));
        $this->assertSame(0.0, (float) $this->verPedido($pedidoId)['saldo']);
    }

    public function test_la_pagina_de_regreso_confirma_el_pago_del_pedido(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearPago($pedidoId)->json('data.pago_id'));
        $this->pagosPorId = [(string) self::PAGO_MP => $this->aprobado($pago)];

        $this->sinSesion();

        $this->get('/mercado-pago/retorno?'.http_build_query([
            'collection_id'      => self::PAGO_MP,
            'collection_status'  => 'approved',
            'payment_id'         => self::PAGO_MP,
            'status'             => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
            'preference_id'      => $pago->preferencia_externa,
        ]))
            ->assertOk()
            ->assertSee('¡Recibimos el pago de tu pedido!')
            ->assertSee('ver el estado de tu pago')
            ->assertDontSee('Recibimos tu anticipo')
            ->assertDontSee('pago de tu saldo')
            // El texto visible (el estado interno de Livewire sí lleva el
            // tipo 'total_revendedor', pero no se muestra).
            ->assertDontSeeText('revendedor')
            ->assertDontSeeText('mayoreo');

        $this->assertSame('aplicado', $pago->fresh()->estado);
    }

    // ---------------------------------------------------------------
    // 12. Avisos: "cliente mayorista", nunca "revendedor"
    // ---------------------------------------------------------------

    public function test_los_avisos_le_llegan_al_cliente_mayorista_y_al_personal_sin_decir_revendedor(): void
    {
        $pedidoId = $this->pedido();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearPago($pedidoId)->json('data.pago_id'));
        $folio = Pedido::withoutGlobalScopes()->find($pedidoId)->folio;
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $respuesta = $this->verificarPago($pedidoId)->assertOk()->assertJsonPath('resultado', 'aplicado');

        $suya = $this->avisosDe(self::MARIA)->sole();
        $this->assertSame("Recibimos tu pago del pedido {$folio}", $suya->titulo);
        $this->assertStringContainsString('Mercado Pago confirmó tu pago de $', $suya->mensaje);
        $this->assertSame($pedidoId, (int) $suya->entidad_id);

        $delStaff = $this->avisosDe(self::ADMIN)->sole();
        $this->assertSame("Pago con Mercado Pago del pedido {$folio}", $delStaff->titulo);
        $this->assertStringContainsString('Mercado Pago confirmó un pago de cliente mayorista de $', $delStaff->mensaje);
        $this->assertStringContainsString($pago->folio, $delStaff->mensaje);
        $this->assertCount(1, $this->avisosDe(self::EMPLEADO));

        $textos = Notificacion::withoutGlobalScopes()->where('tipo', 'pago_mercado_pago')->get()
            ->flatMap(fn ($n) => [$n->titulo, $n->mensaje])
            ->push($respuesta->json('message'));
        foreach ($textos as $texto) {
            $this->assertDoesNotMatchRegularExpression('/revendedor|mayoreo/i', $texto);
        }
    }

    // ---------------------------------------------------------------
    // 13. G7/G8 siguen igual
    // ---------------------------------------------------------------

    public function test_las_rutas_de_g7_y_g8_no_cambian_y_la_nueva_es_solo_del_cliente_mayorista(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($ruta) => $ruta->uri());
        $accion = fn (string $uri) => $rutas[$uri]->getActionName();

        $this->assertSame(PagoMercadoPagoController::class.'@crearAnticipo', $accion('api/pedidos/{id}/anticipo/mercado-pago'));
        $this->assertSame(PagoMercadoPagoController::class.'@verificar', $accion('api/pedidos/{id}/anticipo/mercado-pago/verificar'));
        $this->assertSame(PagoMercadoPagoController::class.'@crearSaldo', $accion('api/pedidos/{id}/saldo/mercado-pago'));
        $this->assertSame(PagoMercadoPagoController::class.'@verificarSaldo', $accion('api/pedidos/{id}/saldo/mercado-pago/verificar'));
        $this->assertSame(PagoMercadoPagoController::class.'@crearMayorista', $accion('api/pedidos/{id}/total/mercado-pago'));
        $this->assertSame(PagoMercadoPagoController::class.'@verificarMayorista', $accion('api/pedidos/{id}/total/mercado-pago/verificar'));

        foreach (['api/pedidos/{id}/total/mercado-pago', 'api/pedidos/{id}/total/mercado-pago/verificar'] as $uri) {
            $middleware = $rutas[$uri]->gatherMiddleware();
            $this->assertContains('role:revendedor', $middleware);
            $this->assertContains('throttle:10,1', $middleware);
            $this->assertSame(['POST'], $rutas[$uri]->methods());
        }
        $this->assertContains('role:cliente_directo', $rutas['api/pedidos/{id}/saldo/mercado-pago']->gatherMiddleware());

        // El anticipo del cliente directo sigue igual.
        $this->como(self::JOSE);
        $pedidoDeJose = (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');
        $this->fingirMercadoPago();
        $this->postJson("/api/pedidos/{$pedidoDeJose}/anticipo/mercado-pago")
            ->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::PEDIDO_BORRADOR]);
        Http::assertNothingSent();
    }
}
