<?php

namespace Tests\Feature\MercadoPago;

use App\Models\Auditoria;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Pago;
use App\Models\ReembolsoMercadoPago;
use App\Models\StockLocal;
use App\Models\VentaDirecta;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reembolso\ReembolsoMercadoPagoService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * Reembolsos automáticos en Mercado Pago al cancelar.
 *
 * Nunca se habla con Mercado Pago de verdad: Http::preventStrayRequests()
 * hace fallar cualquier petición que no esté en Http::fake().
 */
class ReembolsoMercadoPagoTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    /** Token falso, solo para las pruebas (nunca sale a la red). */
    private const TOKEN = 'TEST-token-falso-de-pruebas';

    private const PAGO_MP = '555000111';

    private const URL_REEMBOLSO = 'https://api.mercadopago.com/v1/payments/555000111/refunds';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->conectarMercadoPago();
    }

    private function conectarMercadoPago(array $cambios = []): void
    {
        Tenant::forzar($this->ramirez()->id, fn () => ConfiguracionDistribuidora::query()->firstOrFail()->update(array_merge([
            'mp_access_token'         => self::TOKEN,
            'mp_public_key'           => 'TEST-public-key',
            'mercado_pago_account_id' => '987654321',
            'mp_conectado_at'         => now(),
        ], $cambios)));
    }

    /** Una venta de mostrador de 2 piezas; con $mp, su cobro queda como Mercado Pago. */
    private function vender(bool $mp = true): VentaDirecta
    {
        $this->comoPanel(self::ADMIN);
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])->call('agregar', $renglon['clave'])
            ->set('metodo_pago', 'efectivo')->call('cobrar')->assertSet('errorMsg', '');

        $venta = VentaDirecta::withoutGlobalScopes()->latest('id')->firstOrFail();

        if ($mp) {
            // El punto de venta no cobra con Mercado Pago; se simula el cobro.
            Pago::withoutGlobalScopes()->where('venta_directa_id', $venta->id)->where('direccion', 'entrada')
                ->update(['metodo' => 'mercado_pago', 'proveedor_pago' => 'mercado_pago', 'referencia_externa' => self::PAGO_MP, 'caja_sesion_id' => null]);
        }

        return $venta;
    }

    private function fingirReembolso(int $http = 201, array $cuerpo = ['id' => 777000111, 'status' => 'approved']): void
    {
        Http::fake([self::URL_REEMBOLSO => Http::response($cuerpo, $http)]);
    }

    private function anular(VentaDirecta $venta, string $email = self::ADMIN)
    {
        $this->comoApi($email);

        return $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'El cliente se arrepintió']);
    }

    private function reembolsoDe(VentaDirecta $venta): ReembolsoMercadoPago
    {
        return ReembolsoMercadoPago::withoutGlobalScopes()->where('venta_directa_id', $venta->id)->firstOrFail();
    }

    public function test_cancelar_venta_cobrada_con_mercado_pago_pide_el_reembolso_total_con_idempotencia(): void
    {
        $venta = $this->vender();
        $this->fingirReembolso();

        $this->anular($venta)->assertOk()
            ->assertJsonPath('data.estado', 'anulada')
            ->assertJsonPath('data.reembolsos_mercado_pago.0.estado', 'aprobado')
            ->assertJsonPath('data.reembolsos_mercado_pago.0.mp_reembolso_id', '777000111')
            ->assertJsonMissingPath('data.reembolsos_mercado_pago.0.idempotency_key');

        $reembolso = $this->reembolsoDe($venta);
        $this->assertSame('aprobado', $reembolso->estado);
        $this->assertEquals((float) $venta->total, (float) $reembolso->monto);
        $this->assertFalse($reembolso->parcial);
        $this->assertSame(1, (int) $reembolso->intentos);
        $this->assertNotNull($reembolso->aprobado_at);

        Http::assertSent(function (PeticionHttp $p) use ($reembolso) {
            return $p->url() === self::URL_REEMBOLSO
                && $p->method() === 'POST'
                && $p->header('X-Idempotency-Key')[0] === $reembolso->idempotency_key
                && $p->header('Authorization')[0] === 'Bearer '.self::TOKEN
                && ! array_key_exists('amount', $p->data()); // total: sin monto
        });
        Http::assertSentCount(1);

        $salida = Pago::withoutGlobalScopes()->where('venta_directa_id', $venta->id)->where('direccion', 'salida')->firstOrFail();
        $this->assertSame('mercado_pago', $salida->metodo);
        $this->assertStringContainsString('Reembolso automático en Mercado Pago', $salida->referencia);
        $this->assertSame($salida->id, (int) $reembolso->pago_reembolso_id);

        $this->assertTrue(Auditoria::where('accion', 'mercado_pago.reembolso_solicitado')->where('entidad_id', $reembolso->id)->exists());
        $this->assertTrue(Auditoria::where('accion', 'mercado_pago.reembolso_aprobado')->where('entidad_id', $reembolso->id)->exists());
    }

    public function test_venta_en_efectivo_no_toca_mercado_pago(): void
    {
        Http::fake();
        $venta = $this->vender(mp: false);

        $this->anular($venta)->assertOk()->assertJsonCount(0, 'data.reembolsos_mercado_pago');

        Http::assertNothingSent();
        $this->assertSame(0, ReembolsoMercadoPago::withoutGlobalScopes()->count());
    }

    public function test_si_mercado_pago_rechaza_queda_fallido_y_el_admin_lo_reintenta_con_llave_nueva(): void
    {
        $venta = $this->vender();
        Http::fake([self::URL_REEMBOLSO => Http::sequence()
            ->push(['error' => 'bad_request', 'message' => 'Payment too old to be refunded'], 400)
            ->push(['id' => 777000222, 'status' => 'approved'], 201)]);

        $this->anular($venta)->assertOk()->assertJsonPath('data.reembolsos_mercado_pago.0.estado', 'fallido')
            ->assertJsonPath('data.reembolsos_mercado_pago.0.se_puede_reintentar', true);

        $reembolso = $this->reembolsoDe($venta);
        $this->assertSame('fallido', $reembolso->estado);
        $this->assertStringContainsString('HTTP 400', $reembolso->ultimo_error);
        $this->assertTrue(Auditoria::where('accion', 'mercado_pago.reembolso_fallido')->where('entidad_id', $reembolso->id)->exists());
        $llaveAnterior = $reembolso->idempotency_key;

        // La venta sí quedó cancelada y el stock regresó aunque falló el reembolso.
        $this->assertSame('anulada', $venta->fresh()->estado);

        // En la pantalla se ve el estado y el botón de reintento.
        $this->comoPanel(self::ADMIN);
        Livewire::test('ventas.index')->call('alternar', $venta->id)
            ->assertSee('Reembolso fallido')
            ->assertSeeHtml('data-reintentar-reembolso="'.$reembolso->id.'"')
            ->call('reintentarReembolsoMp', $reembolso->id)
            ->assertSet('errorMsg', '')
            ->assertSee('Reembolsado en Mercado Pago');

        $reembolso->refresh();
        $this->assertSame('aprobado', $reembolso->estado);
        $this->assertSame('777000222', $reembolso->mp_reembolso_id);
        $this->assertNotSame($llaveAnterior, $reembolso->idempotency_key);
        $this->assertSame(2, (int) $reembolso->intentos);
        $this->assertTrue(Auditoria::where('accion', 'mercado_pago.reembolso_reintentado')->where('entidad_id', $reembolso->id)->exists());
    }

    public function test_si_mercado_pago_no_responde_conserva_la_llave_al_reintentar(): void
    {
        $venta = $this->vender();
        Http::fake([self::URL_REEMBOLSO => Http::sequence()
            ->push(['error' => 'internal_error'], 500)
            ->push(['id' => 777000333, 'status' => 'approved'], 201)]);

        $this->anular($venta)->assertOk();

        $reembolso = $this->reembolsoDe($venta);
        // Con la cola síncrona nadie más lo reintentaría: queda fallido.
        $this->assertSame('fallido', $reembolso->estado);
        $llave = $reembolso->idempotency_key;

        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertOk()
            ->assertJsonPath('data.estado', 'aprobado');

        $this->assertSame($llave, $reembolso->fresh()->idempotency_key);
        Http::assertSent(fn (PeticionHttp $p) => $p->header('X-Idempotency-Key')[0] === $llave);
    }

    public function test_reembolso_en_proceso_lo_confirma_el_aviso_de_mercado_pago(): void
    {
        $venta = $this->vender();
        $this->fingirReembolso(201, ['id' => 777000444, 'status' => 'in_process']);

        $this->anular($venta)->assertOk()->assertJsonPath('data.reembolsos_mercado_pago.0.estado', 'procesando');
        $reembolso = $this->reembolsoDe($venta);

        $cobro = Pago::withoutGlobalScopes()->findOrFail($reembolso->pago_id);
        $marcados = app(ReembolsoMercadoPagoService::class)->desdeAviso($cobro, [
            'id' => (int) self::PAGO_MP, 'status' => 'refunded',
            'refunds' => [['id' => 777000444, 'status' => 'approved']],
        ]);

        $this->assertSame(1, $marcados);
        $this->assertSame('aprobado', $reembolso->fresh()->estado);
    }

    public function test_reembolso_parcial_manda_el_monto(): void
    {
        $venta = $this->vender();
        $this->fingirReembolso();
        $cobro = Pago::withoutGlobalScopes()->where('venta_directa_id', $venta->id)->where('direccion', 'entrada')->firstOrFail();
        $this->comoPanel(self::ADMIN);

        $reembolso = app(ReembolsoMercadoPagoService::class)->solicitar($cobro, 50.0, 'Parcial', null);

        $this->assertTrue($reembolso->fresh()->parcial);
        $this->assertSame('aprobado', $reembolso->fresh()->estado);
        Http::assertSent(fn (PeticionHttp $p) => (float) ($p->data()['amount'] ?? 0) === 50.0);

        // No se puede pedir más de lo que queda.
        $this->expectException(\App\Exceptions\OperacionInvalidaException::class);
        app(ReembolsoMercadoPagoService::class)->solicitar($cobro, (float) $cobro->monto, 'Demasiado', null);
    }

    public function test_sin_cuenta_de_mercado_pago_conectada_queda_fallido_sin_llamar(): void
    {
        $venta = $this->vender();
        Http::fake();
        $this->conectarMercadoPago(['mp_conectado_at' => null]);

        $this->anular($venta)->assertOk()->assertJsonPath('data.reembolsos_mercado_pago.0.estado', 'fallido');

        Http::assertNothingSent();
    }

    public function test_reintentar_respeta_permisos_y_distribuidora(): void
    {
        $venta = $this->vender();
        Http::fake([self::URL_REEMBOLSO => Http::sequence()
            ->push(['error' => 'bad_request'], 400)
            ->push(['id' => 777000555, 'status' => 'approved'], 201)]);
        $this->anular($venta)->assertOk();
        $reembolso = $this->reembolsoDe($venta);

        // Otra distribuidora: no existe.
        $this->comoApi(self::OTRA_ADMIN);
        $this->getJson("/api/reembolsos-mercado-pago/{$reembolso->id}")->assertNotFound();
        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertNotFound();

        // Empleado sin «Cancelar ventas»: prohibido.
        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertForbidden();

        // Cliente: ni siquiera entra a la ruta.
        $this->comoApi(self::JOSE);
        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertForbidden();

        $this->assertSame('fallido', $reembolso->fresh()->estado);

        // Con permiso sí (y uno aprobado ya no se reintenta).
        $this->staffDe(self::EMPLEADO)->forceFill(['permisos_finos' => [PermisoModuloService::VENTAS_CANCELAR => true]])->save();
        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->postJson("/api/reembolsos-mercado-pago/{$reembolso->id}/reintentar")->assertStatus(409);
    }
}
