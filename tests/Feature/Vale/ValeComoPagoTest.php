<?php

namespace Tests\Feature\Vale;

use App\Models\ClienteDirecto;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\CambiarEstadoPedidoService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-167 — Un vale aplicado a un pedido cuenta como pago.
 *
 * Antes, aplicar un vale le quitaba el saldo al vale pero el pedido seguía
 * debiendo lo mismo: el cliente perdía ese dinero. Además se podía aplicar
 * más de lo que se debía, y a pedidos en borrador o rechazados.
 *
 * Decisión del equipo: el vale también cubre el anticipo.
 *
 * Cuentas demo: José (cliente directo, aplica su vale desde la app) y el
 * empleado (emite el vale y cobra en mostrador). La demo cobra $100 de
 * anticipo por par, y los pedidos de aquí son de 2 pares: $200 de anticipo.
 */
class ValeComoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function fichaDeJose(): int
    {
        return (int) ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->value('id');
    }

    /** José arma su pedido de 2 pares desde la app; si $enviar, también lo envía. */
    private function pedidoDeJose(bool $enviar = true): int
    {
        $this->como(self::JOSE);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => $this->fichaDeJose(),
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

    /** El empleado le emite a José un vale. */
    private function valeParaJose(float $monto): int
    {
        $this->como(self::EMPLEADO);

        return (int) $this->postJson('/api/vales', [
            'propietario_tipo' => 'cliente_directo',
            'propietario_id'   => $this->fichaDeJose(),
            'monto_original'   => $monto,
            'motivo'           => 'Prueba TG-167',
        ])->assertCreated()->json('data.id');
    }

    /** José aplica su vale desde la app. */
    private function aplicar(int $valeId, int $pedidoId, float $monto)
    {
        $this->como(self::JOSE);

        return $this->postJson("/api/vales/{$valeId}/aplicar", [
            'monto'     => $monto,
            'pedido_id' => $pedidoId,
        ]);
    }

    /** El pedido tal como lo ve José en la app. */
    private function verPedido(int $pedidoId): array
    {
        $this->como(self::JOSE);

        return $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->json('data');
    }

    private function total(int $pedidoId): float
    {
        return (float) Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->total;
    }

    private function saldoDelVale(int $valeId): float
    {
        return (float) Vale::withoutGlobalScopes()->findOrFail($valeId)->saldo_actual;
    }

    // ------------------------------------------------------------------
    // El vale cuenta como pago
    // ------------------------------------------------------------------

    /** El caso del reporte: antes José perdía los $300 y seguía debiendo todo. */
    public function test_aplicar_un_vale_baja_lo_que_se_debe(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $total = $this->total($pedidoId);
        $this->assertGreaterThan(300, $total);

        $valeId = $this->valeParaJose(300);
        $this->aplicar($valeId, $pedidoId, 300)->assertOk();

        $pedido = $this->verPedido($pedidoId);

        $this->assertEqualsWithDelta(300, $pedido['pagado'], 0.001);
        $this->assertEqualsWithDelta(300, $pedido['pagado_con_vales'], 0.001);
        $this->assertEqualsWithDelta($total - 300, $pedido['saldo'], 0.001);
        $this->assertEqualsWithDelta(0, $this->saldoDelVale($valeId), 0.001);
    }

    public function test_el_vale_cubre_el_anticipo(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $this->assertEqualsWithDelta(200, $this->verPedido($pedidoId)['anticipo_pendiente'], 0.001);

        $this->aplicar($this->valeParaJose(200), $pedidoId, 200)->assertOk();

        $pedido = $this->verPedido($pedidoId);
        $this->assertEqualsWithDelta(200, $pedido['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta(0, $pedido['anticipo_pendiente'], 0.001);
    }

    /** Un vale más grande que el anticipo cubre el anticipo y el resto va al saldo. */
    public function test_el_anticipo_pagado_no_pasa_del_requerido(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $total = $this->total($pedidoId);

        $this->aplicar($this->valeParaJose(300), $pedidoId, 300)->assertOk();

        $pedido = $this->verPedido($pedidoId);
        $this->assertEqualsWithDelta(200, $pedido['anticipo_pagado'], 0.001);
        $this->assertEqualsWithDelta($total - 300, $pedido['saldo'], 0.001);
    }

    public function test_despues_del_vale_el_mostrador_solo_cobra_lo_que_falta(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $total = $this->total($pedidoId);
        $this->aplicar($this->valeParaJose(300), $pedidoId, 300)->assertOk();

        $this->como(self::EMPLEADO);

        // El anticipo ya quedó cubierto con el vale.
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", ['tipo' => 'anticipo', 'metodo' => 'efectivo', 'monto' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);

        // El total completo ya no cabe; lo que falta, sí.
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", ['tipo' => 'saldo_pedido', 'metodo' => 'efectivo', 'monto' => $total])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monto']);

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", ['tipo' => 'saldo_pedido', 'metodo' => 'efectivo', 'monto' => $total - 300])
            ->assertCreated();

        $this->assertEqualsWithDelta(0, $this->verPedido($pedidoId)['saldo'], 0.001);
    }

    public function test_en_la_web_el_pedido_muestra_lo_pagado_con_vales(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $this->aplicar($this->valeParaJose(300), $pedidoId, 300)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSee('Incluye $300.00 en vales');
    }

    // ------------------------------------------------------------------
    // Lo que ya no se permite
    // ------------------------------------------------------------------

    /** Antes: un vale de más se gastaba completo aunque el pedido costara menos. */
    public function test_no_se_aplica_mas_de_lo_que_se_debe(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $total = $this->total($pedidoId);

        $valeId = $this->valeParaJose($total + 500);
        $this->aplicar($valeId, $pedidoId, $total + 500)->assertOk();

        $this->assertEqualsWithDelta(0, $this->verPedido($pedidoId)['saldo'], 0.001);

        // Lo que sobró se queda en el vale, y el vale sigue activo.
        $vale = Vale::withoutGlobalScopes()->findOrFail($valeId);
        $this->assertEqualsWithDelta(500, (float) $vale->saldo_actual, 0.001);
        $this->assertSame('activo', $vale->estado);
    }

    public function test_a_un_pedido_ya_pagado_no_se_le_aplica_vale(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $total = $this->total($pedidoId);
        $this->aplicar($this->valeParaJose($total), $pedidoId, $total)->assertOk();

        $otroVale = $this->valeParaJose(100);
        $this->aplicar($otroVale, $pedidoId, 100)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pedido_id']);

        $this->assertEqualsWithDelta(100, $this->saldoDelVale($otroVale), 0.001);
    }

    public function test_a_un_borrador_no_se_le_aplica_vale(): void
    {
        $pedidoId = $this->pedidoDeJose(enviar: false);
        $valeId = $this->valeParaJose(100);

        $this->aplicar($valeId, $pedidoId, 100)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pedido_id']);

        $this->assertEqualsWithDelta(100, $this->saldoDelVale($valeId), 0.001);
    }

    public function test_a_un_pedido_rechazado_no_se_le_aplica_vale(): void
    {
        $pedidoId = $this->pedidoDeJose();
        $valeId = $this->valeParaJose(100);

        $this->como(self::EMPLEADO);
        app(CambiarEstadoPedidoService::class)->cambiar(Pedido::withoutGlobalScopes()->findOrFail($pedidoId), 'rechazado');

        $this->aplicar($valeId, $pedidoId, 100)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pedido_id']);

        $this->assertEqualsWithDelta(100, $this->saldoDelVale($valeId), 0.001);
    }
}
