<?php

namespace Tests\Feature\MercadoPago;

use App\Http\Controllers\Api\PagoMercadoPagoController;
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
use App\Services\Catalogo\CatalogoVisible;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pago\AplicarPagoMercadoPagoAction;
use App\Services\Pago\CrearPagoAnticipoMercadoPagoAction;
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
use ReflectionMethod;
use Tests\TestCase;

/**
 * TG-227 (G8) — El cliente directo paga el SALDO de su pedido con Checkout
 * Pro contra la cuenta de Mercado Pago de la distribuidora.
 *
 * Reglas: saldo completo (sin pagos parciales), solo con el anticipo cubierto
 * y con el pedido en la distribuidora (recibido o listo para entrega). Un
 * solo enlace vivo por pedido. El mostrador sigue cobrando a mano.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests() hace
 * fallar cualquier petición que no esté en Http::fake().
 */
class SaldoCheckoutProTest extends TestCase
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

    private const PAGO_MP = 555000222;

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

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function fichaDe(string $email): int
    {
        $usuarioId = Usuario::where('email', $email)->value('id');

        return (int) ClienteDirecto::withoutGlobalScopes()
            ->when(
                $usuarioId,
                fn ($consulta) => $consulta->where('usuario_id', $usuarioId),
                fn ($consulta) => $consulta->where('email', $email)
            )
            ->value('id');
    }

    /** Un pedido de 2 pares de José (o el empleado por Ana), enviado. */
    private function pedido(string $quien = self::JOSE, string $cliente = self::JOSE, bool $enviar = true): int
    {
        $this->como($quien);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => $this->fichaDe($cliente),
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

        if ($enviar) {
            $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();
        }

        return $pedidoId;
    }

    /** El anticipo ya se pagó en mostrador y el pedido llegó a la distribuidora. */
    private function pedidoListoParaSaldo(string $estado = 'recibido_distribuidora'): int
    {
        $pedidoId = $this->pedido();
        $this->pagarEnMostrador($pedidoId, $this->verPedido($pedidoId)['anticipo_pendiente']);
        $this->cambiarEstado($pedidoId, $estado);

        return $pedidoId;
    }

    private function cambiarEstado(int $pedidoId, string $estado): void
    {
        DB::table('pedidos')->where('id', $pedidoId)->update(['estado' => $estado]);
    }

    private function verPedido(int $pedidoId): array
    {
        $this->como(self::JOSE);

        return $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data');
    }

    private function pagarEnMostrador(int $pedidoId, float $monto, string $tipo = 'anticipo'): void
    {
        $this->como(self::EMPLEADO);

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => $tipo,
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

    private function crearSaldo(int $pedidoId)
    {
        $this->como(self::JOSE);

        return $this->postJson("/api/pedidos/{$pedidoId}/saldo/mercado-pago");
    }

    private function verificarSaldo(int $pedidoId, array $datos = [])
    {
        $this->como(self::JOSE);

        return $this->postJson("/api/pedidos/{$pedidoId}/saldo/mercado-pago/verificar", $datos);
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

    // ---------------------------------------------------------------
    // 1. Crear la preferencia del saldo
    // ---------------------------------------------------------------

    public function test_crea_la_preferencia_por_el_saldo_completo_con_el_token_de_la_distribuidora(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $antes = $this->verPedido($pedidoId);
        $saldo = (float) $antes['saldo'];
        $this->assertGreaterThan(0, $saldo);
        $this->assertTrue($antes['puede_pagar_saldo_mercado_pago']);
        $this->assertNull($antes['pago_mercado_pago_pendiente_tipo']);
        $this->fingirMercadoPago();

        $respuesta = $this->crearSaldo($pedidoId);

        $respuesta->assertCreated()
            ->assertJsonPath('data.tipo', 'saldo_pedido')
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.preferencia_id', self::PREF_1)
            ->assertJsonPath('data.init_point', $this->initPoint(self::PREF_1))
            ->assertJsonPath('data.reutilizado', false)
            ->assertJsonPath('message', 'Abre el enlace para pagar el saldo de tu pedido con Mercado Pago.');
        $this->assertSame($saldo, (float) $respuesta->json('data.monto'));
        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());

        $pago = $this->pagoMp($respuesta->json('data.pago_id'));
        $this->assertSame('saldo_pedido', $pago->tipo);
        $this->assertSame('pendiente', $pago->estado);
        $this->assertSame('mercado_pago', $pago->metodo);
        $this->assertSame(self::PREF_1, $pago->preferencia_externa);
        $this->assertNull($pago->registrado_por_staff_id);

        Http::assertSentCount(1);
        Http::assertSent(function (PeticionHttp $peticion) use ($pago, $saldo) {
            $datos = $peticion->data();

            return $peticion->url() === self::URL_PREFERENCIAS
                && $peticion->method() === 'POST'
                && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && (float) $datos['items'][0]['unit_price'] === $saldo
                && $datos['items'][0]['quantity'] === 1
                && str_starts_with($datos['items'][0]['title'], 'Saldo del pedido ')
                && $datos['external_reference'] === $pago->referenciaMercadoPago()
                && $datos['metadata']['tipo'] === 'saldo_pedido'
                && $datos['metadata']['pago_id'] === $pago->id
                && $datos['binary_mode'] === true
                && $datos['expires'] === true
                && $datos['auto_return'] === 'approved';
        });

        // Pendiente no es pagado.
        $despues = $this->verPedido($pedidoId);
        $this->assertSame($saldo, (float) $despues['saldo']);
        $this->assertTrue($despues['pago_mercado_pago_pendiente']);
        $this->assertSame('saldo_pedido', $despues['pago_mercado_pago_pendiente_tipo']);

        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.preferencia')->sole();
        $this->assertSame('saldo_pedido', $auditoria->datos_nuevos['tipo']);
    }

    // ---------------------------------------------------------------
    // 2 y 3. Reutilizar o reemplazar el enlace
    // ---------------------------------------------------------------

    public function test_reutiliza_el_enlace_vigente_y_si_cambio_el_saldo_lo_reemplaza(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $saldo = (float) $this->verPedido($pedidoId)['saldo'];
        $this->fingirMercadoPago([self::PREF_1, self::PREF_2]);

        $primero = $this->crearSaldo($pedidoId)->assertCreated();
        $this->crearSaldo($pedidoId)->assertOk()
            ->assertJsonPath('data.pago_id', $primero->json('data.pago_id'))
            ->assertJsonPath('data.reutilizado', true);

        // El mostrador cobra una parte a mano (no se bloquea).
        $this->pagarEnMostrador($pedidoId, 100, 'saldo_pedido');

        $segundo = $this->crearSaldo($pedidoId)->assertCreated()
            ->assertJsonPath('data.preferencia_id', self::PREF_2);
        $this->assertSame(round($saldo - 100, 2), (float) $segundo->json('data.monto'));

        $this->assertSame('fallido', $this->pagoMp($primero->json('data.pago_id'))->estado);
        $this->assertSame('pendiente', $this->pagoMp($segundo->json('data.pago_id'))->estado);
        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->method() === 'PUT'
            && $peticion->url() === self::URL_PREFERENCIAS.'/'.self::PREF_1
            && $peticion->data()['expires'] === true);
        $this->assertSame(1, Auditoria::where('accion', 'pago.mercado_pago.reemplazado')->count());
    }

    public function test_un_anticipo_pendiente_con_mercado_pago_se_reemplaza_al_pedir_el_saldo(): void
    {
        $pedidoId = $this->pedido();
        $anticipo = (float) $this->verPedido($pedidoId)['anticipo_pendiente'];
        $this->fingirMercadoPago([self::PREF_1, self::PREF_2]);

        $this->como(self::JOSE);
        $enlaceAnticipo = $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago")->assertCreated();

        // Mientras tanto paga el anticipo en mostrador y el pedido llega.
        $this->pagarEnMostrador($pedidoId, $anticipo);
        $this->cambiarEstado($pedidoId, 'recibido_distribuidora');

        $saldo = $this->crearSaldo($pedidoId)->assertCreated()
            ->assertJsonPath('data.preferencia_id', self::PREF_2)
            ->assertJsonPath('data.tipo', 'saldo_pedido');

        $anterior = $this->pagoMp($enlaceAnticipo->json('data.pago_id'));
        $this->assertSame('fallido', $anterior->estado);
        $this->assertSame('pendiente', $this->pagoMp($saldo->json('data.pago_id'))->estado);
        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->method() === 'PUT'
            && $peticion->url() === self::URL_PREFERENCIAS.'/'.self::PREF_1);

        // Si alguien alcanzó a pagar el enlace viejo, se aplica igual y queda
        // marcado para que el personal lo revise.
        $this->assertSame(
            AplicarPagoMercadoPagoAction::APLICADO,
            app(AplicarPagoMercadoPagoAction::class)->ejecutar($anterior, $this->aprobado($anterior, ['id' => 9001]))
        );
        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole();
        $this->assertTrue($auditoria->datos_nuevos['excede_anticipo']);
    }

    // ---------------------------------------------------------------
    // 4, 5 y 6. Cuándo NO se puede pagar el saldo
    // ---------------------------------------------------------------

    public function test_con_el_anticipo_pendiente_pide_pagar_primero_el_anticipo(): void
    {
        $pedidoId = $this->pedido();
        $this->cambiarEstado($pedidoId, 'recibido_distribuidora');
        $this->assertFalse($this->verPedido($pedidoId)['puede_pagar_saldo_mercado_pago']);
        $this->fingirMercadoPago();

        $this->crearSaldo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SALDO_ANTES_DE_ANTICIPO]);

        $this->assertCount(0, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    public function test_solo_se_paga_el_saldo_cuando_el_pedido_ya_llego_a_la_distribuidora(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo('colocado');
        $this->fingirMercadoPago();

        foreach (['colocado', 'confirmado', 'solicitado_fabrica', 'en_transito'] as $estado) {
            $this->cambiarEstado($pedidoId, $estado);
            $this->crearSaldo($pedidoId)->assertStatus(422)
                ->assertExactJson(['message' => MercadoPagoException::SALDO_TODAVIA_NO]);
        }
        $this->assertFalse($this->verPedido($pedidoId)['puede_pagar_saldo_mercado_pago']);

        $this->cambiarEstado($pedidoId, 'rechazado');
        $this->crearSaldo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::PEDIDO_CERRADO]);

        $borrador = $this->pedido(enviar: false);
        $this->crearSaldo($borrador)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SALDO_TODAVIA_NO]);

        Http::assertNothingSent();
        $this->assertCount(1, $this->pagosDelPedido($pedidoId));

        // Listo para entrega sí.
        $this->cambiarEstado($pedidoId, 'listo_entrega');
        $this->crearSaldo($pedidoId)->assertCreated()->assertJsonPath('data.tipo', 'saldo_pedido');
    }

    public function test_sin_saldo_pendiente_no_crea_nada(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->pagarEnMostrador($pedidoId, $this->verPedido($pedidoId)['saldo'], 'saldo_pedido');
        $this->assertFalse($this->verPedido($pedidoId)['puede_pagar_saldo_mercado_pago']);
        $this->fingirMercadoPago();

        $this->crearSaldo($pedidoId)->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_SALDO_PENDIENTE]);

        $this->assertCount(2, $this->pagosDelPedido($pedidoId));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // 7. Quién puede pagar
    // ---------------------------------------------------------------

    public function test_solo_el_cliente_directo_dueno_puede_pagar_el_saldo(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $url = "/api/pedidos/{$pedidoId}/saldo/mercado-pago";

        $this->app['auth']->forgetGuards();
        $this->postJson($url)->assertUnauthorized();

        $this->como(self::EMPLEADO);
        $this->postJson($url)->assertForbidden();
        $this->postJson("{$url}/verificar")->assertForbidden();

        $this->como(self::MARIA);
        $this->postJson($url)->assertForbidden();

        // El pedido de Ana (lo capturó el empleado) no es de José: 404.
        $pedidoDeAna = $this->pedido(self::EMPLEADO, 'ana.garcia@cliente.test');
        $this->cambiarEstado($pedidoDeAna, 'recibido_distribuidora');
        $this->crearSaldo($pedidoDeAna)->assertNotFound();
        $this->verificarSaldo($pedidoDeAna)->assertNotFound();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // 8 y 9. Verificar
    // ---------------------------------------------------------------

    public function test_verificar_aplica_el_saldo_y_el_pedido_ya_se_puede_entregar(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearSaldo($pedidoId)->json('data.pago_id'));
        $this->pagosEnMercadoPago = [$this->aprobado($pago)];

        $respuesta = $this->verificarSaldo($pedidoId);

        $respuesta->assertOk()
            ->assertJsonPath('resultado', 'aplicado')
            ->assertJsonPath('message', 'Recibimos el pago de tu saldo. ¡Gracias!')
            ->assertJsonPath('data.saldo', 0)
            ->assertJsonPath('data.pago_mercado_pago_pendiente', false)
            ->assertJsonPath('data.pago_mercado_pago_pendiente_tipo', null)
            ->assertJsonPath('data.puede_pagar_saldo_mercado_pago', false);

        $pago->refresh();
        $this->assertSame('aplicado', $pago->estado);
        $this->assertSame((string) self::PAGO_MP, $pago->referencia_externa);

        $auditoria = Auditoria::where('accion', 'pago.mercado_pago.aplicado')->sole();
        $this->assertFalse($auditoria->datos_nuevos['excede_saldo']);
        $this->assertFalse($auditoria->datos_nuevos['excede_anticipo']);

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
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearSaldo($pedidoId)->json('data.pago_id'));

        // Otro monto.
        $this->pagosPorId = ['777' => $this->aprobado($pago, ['id' => 777, 'transaction_amount' => 1.0])];
        $this->verificarSaldo($pedidoId, ['payment_id' => '777'])->assertOk()
            ->assertJsonPath('resultado', 'no_cuadra')
            ->assertJsonPath('message', 'Mercado Pago tiene un pago que no coincide con este saldo, así que no se aplicó. No vuelvas a pagar: la distribuidora lo revisará contigo.');

        // Otra referencia.
        $this->pagosPorId = ['778' => $this->aprobado($pago, ['id' => 778, 'external_reference' => 'FWP-1-1'])];
        $this->verificarSaldo($pedidoId, ['payment_id' => '778'])->assertOk()
            ->assertJsonPath('resultado', 'no_cuadra');

        $this->enElPanel(self::EMPLEADO);
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoMpId', '777')
            ->call('verificarMercadoPago')
            ->assertSet('mensaje', 'Mercado Pago tiene un pago que no coincide con este saldo (referencia, monto, moneda o cuenta) y no se aplicó. Revísalo en tu cuenta de Mercado Pago antes de registrar algo a mano.');

        $this->assertSame('pendiente', $pago->fresh()->estado);
        $this->assertSame(0, Auditoria::where('accion', 'pago.mercado_pago.aplicado')->count());
    }

    // ---------------------------------------------------------------
    // 10 y 11. Página de regreso y avisos
    // ---------------------------------------------------------------

    public function test_la_pagina_de_regreso_confirma_el_saldo(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearSaldo($pedidoId)->json('data.pago_id'));
        $this->pagosPorId = [(string) self::PAGO_MP => $this->aprobado($pago)];

        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();

        $this->get('/mercado-pago/retorno?'.http_build_query([
            'collection_id'      => self::PAGO_MP,
            'collection_status'  => 'approved',
            'payment_id'         => self::PAGO_MP,
            'status'             => 'approved',
            'external_reference' => $pago->referenciaMercadoPago(),
            'preference_id'      => $pago->preferencia_externa,
        ]))
            ->assertOk()
            ->assertSee('¡Recibimos el pago de tu saldo!')
            ->assertSee('ver el estado de tu pago')
            ->assertDontSee('Recibimos tu anticipo');

        $this->assertSame('aplicado', $pago->fresh()->estado);
        $this->assertSame(0.0, (float) $this->verPedido($pedidoId)['saldo']);
    }

    public function test_los_avisos_dicen_saldo_y_la_verificacion_del_anticipo_no_ve_el_saldo(): void
    {
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $pago = $this->pagoMp($this->crearSaldo($pedidoId)->json('data.pago_id'));
        $folio = Pedido::withoutGlobalScopes()->find($pedidoId)->folio;

        // La ruta del anticipo solo mira pagos del anticipo (y no pregunta nada).
        $enviadas = count(Http::recorded());
        $this->como(self::JOSE);
        $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago/verificar")
            ->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_PAGO_POR_CONFIRMAR]);
        $this->assertCount($enviadas, Http::recorded());

        $this->pagosEnMercadoPago = [$this->aprobado($pago)];
        $this->verificarSaldo($pedidoId)->assertOk()->assertJsonPath('resultado', 'aplicado');

        $jose = Usuario::where('email', self::JOSE)->value('id');
        $suya = Notificacion::withoutGlobalScopes()->where('usuario_id', $jose)->where('tipo', 'pago_mercado_pago')->sole();
        $this->assertSame("Recibimos el pago de tu saldo del pedido {$folio}", $suya->titulo);

        $admin = Usuario::where('email', self::ADMIN)->value('id');
        $delStaff = Notificacion::withoutGlobalScopes()->where('usuario_id', $admin)->where('tipo', 'pago_mercado_pago')->sole();
        $this->assertStringContainsString('Mercado Pago confirmó un pago del saldo de $', $delStaff->mensaje);
        $this->assertStringContainsString($pago->folio, $delStaff->mensaje);
    }

    // ---------------------------------------------------------------
    // 12. G7 sigue igual
    // ---------------------------------------------------------------

    public function test_las_rutas_y_la_accion_del_anticipo_no_cambian(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->mapWithKeys(fn ($ruta) => [$ruta->uri() => $ruta->getActionName()]);

        $this->assertSame(PagoMercadoPagoController::class.'@crearAnticipo', $rutas['api/pedidos/{id}/anticipo/mercado-pago']);
        $this->assertSame(PagoMercadoPagoController::class.'@verificar', $rutas['api/pedidos/{id}/anticipo/mercado-pago/verificar']);
        $this->assertSame(PagoMercadoPagoController::class.'@crearSaldo', $rutas['api/pedidos/{id}/saldo/mercado-pago']);
        $this->assertSame(PagoMercadoPagoController::class.'@verificarSaldo', $rutas['api/pedidos/{id}/saldo/mercado-pago/verificar']);

        $firma = new ReflectionMethod(CrearPagoAnticipoMercadoPagoAction::class, 'ejecutar');
        $this->assertSame(1, $firma->getNumberOfParameters());
        $this->assertSame(Pedido::class, $firma->getParameters()[0]->getType()->getName());

        // Con el anticipo cubierto, la ruta del anticipo lo dice como siempre.
        $pedidoId = $this->pedidoListoParaSaldo();
        $this->fingirMercadoPago();
        $this->como(self::JOSE);
        $this->postJson("/api/pedidos/{$pedidoId}/anticipo/mercado-pago")
            ->assertStatus(422)
            ->assertExactJson(['message' => MercadoPagoException::SIN_ANTICIPO_PENDIENTE]);
        Http::assertNothingSent();
    }
}
