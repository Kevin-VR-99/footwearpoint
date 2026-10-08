<?php

namespace Tests\Feature\Pedido;

use App\Models\CicloCompra;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Vale\AplicarValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-275 — El dinero que entra cubre primero el anticipo.
 *
 * Lo reportaron en el mostrador: si el personal cobraba y lo marcaba como
 * "Saldo", ese dinero no contaba para el anticipo, y desde K8 (TG-215) el
 * pedido ya no se le pedía a la fábrica aunque el cliente ya hubiera pagado.
 *
 * La cuenta vive en un solo lugar, RegistrarPagoPedidoAction::resumen(), que
 * es la que usan el panel, la app, la solicitud a fábrica y Mercado Pago.
 */
class SaldoCubreElAnticipoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const MARIA = 'maria.lopez@revendedor.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->conAnticipoDe(100);
    }

    // ------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function conAnticipoDe(float $monto): void
    {
        ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))
            ->update(['anticipo_por_producto' => $monto]);
    }

    /** Arma y envía un pedido de 2 pares desde la app. */
    private function pedidoEnviadoPor(string $email, int $cantidad = 2): int
    {
        $this->como($email);

        $pedidoId = (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');

        $variante = DisponibilidadVarianteCampana::query()
            ->where('estado', 'disponible')
            ->orderBy('id')
            ->firstOrFail();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => $cantidad,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    private function pedido(int $id): Pedido
    {
        return Pedido::withoutGlobalScopes()->findOrFail($id);
    }

    private function resumen(int $pedidoId): array
    {
        return app(RegistrarPagoPedidoAction::class)->resumen($this->pedido($pedidoId));
    }

    private function cobrar(int $pedidoId, string $tipo, float $monto)
    {
        $this->como(self::EMPLEADO);

        return $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => $tipo,
            'metodo' => 'efectivo',
            'monto' => $monto,
        ]);
    }

    /** Cierra el ciclo y lo solicita a fábrica. */
    private function solicitarAFabrica(int $cicloId): array
    {
        $this->como(self::EMPLEADO);

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();

        return $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")->assertOk()->json('data');
    }

    // ------------------------------------------------------------------
    // El arreglo
    // ------------------------------------------------------------------

    public function test_un_pago_de_saldo_que_cubre_el_anticipo_lo_deja_en_cero_y_el_pedido_se_manda(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        // Debe $200 de anticipo y el personal cobra justo eso, pero marcado
        // como saldo: es el caso que reportaron del mostrador.
        $this->cobrar($pedidoId, 'saldo_pedido', 200)->assertCreated();

        $resumen = $this->resumen($pedidoId);

        $this->assertEqualsWithDelta(200.00, $resumen['anticipo_requerido'], 0.001);
        $this->assertEqualsWithDelta(200.00, $resumen['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_pendiente'], 0.001);

        $datos = $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
        $this->assertSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
        $this->assertNotEmpty($datos['consolidado']);
    }

    public function test_un_pago_de_saldo_menor_al_anticipo_deja_pendiente_la_diferencia(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->cobrar($pedidoId, 'saldo_pedido', 120)->assertCreated();

        $resumen = $this->resumen($pedidoId);

        $this->assertEqualsWithDelta(120.00, $resumen['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta(80.00, $resumen['anticipo_pendiente'], 0.001);

        // Y sigue sin irse a fábrica: le falta anticipo.
        $this->solicitarAFabrica($cicloId);

        $this->assertNotSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
        $this->assertSame('abierto', CicloCompra::withoutGlobalScopes()
            ->findOrFail($this->pedido($pedidoId)->ciclo_compra_id)->estado);
    }

    /** Pagar más de lo que pide el anticipo no infla el anticipo pagado. */
    public function test_el_anticipo_pagado_nunca_pasa_del_requerido(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);

        $this->cobrar($pedidoId, 'saldo_pedido', 1000)->assertCreated();

        $resumen = $this->resumen($pedidoId);

        $this->assertEqualsWithDelta(200.00, $resumen['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_pendiente'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $resumen['pagado'], 0.001);
    }

    // ------------------------------------------------------------------
    // Lo que ya funcionaba sigue igual
    // ------------------------------------------------------------------

    public function test_el_pago_de_tipo_anticipo_sigue_contando(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->cobrar($pedidoId, 'anticipo', 200)->assertCreated();

        $this->assertEqualsWithDelta(0.00, $this->resumen($pedidoId)['anticipo_pendiente'], 0.001);

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
    }

    public function test_el_vale_sigue_cubriendo_el_anticipo(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->como(self::EMPLEADO);
        $valeId = (int) $this->postJson('/api/vales', [
            'propietario_tipo' => 'cliente_directo',
            'propietario_id' => ClienteDirecto::withoutGlobalScopes()
                ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
                ->value('id'),
            'monto_original' => 200,
            'motivo' => 'Prueba TG-275',
        ])->assertCreated()->json('data.id');

        app(AplicarValeAction::class)->ejecutar(Vale::findOrFail($valeId), [
            'monto' => 200,
            'pedido_id' => $pedidoId,
        ]);

        $resumen = $this->resumen($pedidoId);

        $this->assertEqualsWithDelta(200.00, $resumen['pagado_con_vales'], 0.001);
        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_pendiente'], 0.001);

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
    }

    public function test_pagado_a_medias_sigue_contando_como_no_pagado(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->cobrar($pedidoId, 'anticipo', 120)->assertCreated();

        $this->solicitarAFabrica($cicloId);

        $this->assertNotSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
    }

    public function test_el_revendedor_no_pide_anticipo_aunque_pague_saldo(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::MARIA);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->cobrar($pedidoId, 'saldo_pedido', 150)->assertCreated();

        $resumen = $this->resumen($pedidoId);

        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_requerido'], 0.001);
        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta(0.00, $resumen['anticipo_pendiente'], 0.001);

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
    }

    /** El saldo del pedido no cambia: el anticipo es parte de lo mismo. */
    public function test_el_saldo_del_pedido_no_cambia(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $total = round((float) $this->pedido($pedidoId)->total, 2);

        $this->cobrar($pedidoId, 'saldo_pedido', 200)->assertCreated();

        $this->assertEqualsWithDelta($total - 200, $this->resumen($pedidoId)['saldo'], 0.001);
    }

    public function test_un_anticipo_despues_de_tenerlo_cubierto_se_sigue_rechazando(): void
    {
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);

        $this->cobrar($pedidoId, 'saldo_pedido', 200)->assertCreated();

        $this->cobrar($pedidoId, 'anticipo', 50)
            ->assertStatus(422)
            ->assertJsonPath('errors.tipo.0', 'Este pedido ya cubrió el anticipo requerido.');
    }
}
