<?php

namespace Tests\Feature\Pedido;

use App\Models\CicloCompra;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CambiarEstadoPedidoService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El pedido del revendedor en el ciclo de compra (E9 / E10) y sus pagos
 * (E8-02). Pruebas agregadas en TG-162: hasta ahora nada comprobaba que el
 * pedido que manda la revendedora desde la app entre al ciclo, ni cómo se
 * cobra por partes en el mostrador.
 *
 * Usa las cuentas de demo: María (revendedora, arma su pedido desde la app)
 * y el empleado (cobra en el mostrador).
 */
class PedidoRevendedorCicloYPagosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
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

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    /** Una variante que se puede pedir: publicada, campaña activa y disponible. */
    private function variantePedible(): DisponibilidadVarianteCampana
    {
        $disponibilidad = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->first();

        $this->assertNotNull($disponibilidad, 'El seeder demo no dejó ninguna variante que se pueda pedir.');

        return $disponibilidad;
    }

    /** María abre un pedido vacío desde la app. */
    private function pedidoVacioDeMaria(): int
    {
        $this->como(self::MARIA);

        $maria = Usuario::where('email', self::MARIA)->firstOrFail();

        return (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id'    => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $this->distribuidora()->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');
    }

    /** María arma un pedido con una línea, sin enviarlo. */
    private function borradorDeMaria(): int
    {
        $pedidoId = $this->pedidoVacioDeMaria();

        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id'         => $variante->variante_id,
            'cantidad'            => 2,
        ])->assertCreated();

        return $pedidoId;
    }

    /** María arma y envía su pedido desde la app. */
    private function pedidoEnviadoDeMaria(): int
    {
        $pedidoId = $this->borradorDeMaria();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    private function pedido(int $id): Pedido
    {
        return Pedido::withoutGlobalScopes()->findOrFail($id);
    }

    private function pagar(int $pedidoId, float $monto, string $tipo = 'saldo_pedido')
    {
        return $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => $tipo,
            'metodo' => 'efectivo',
            'monto'  => $monto,
        ]);
    }

    /** Compara como números: en el JSON 650.0 llega como 650. */
    private function assertPagadoYSaldo($respuesta, float $pagado, float $saldo): void
    {
        $this->assertEqualsWithDelta($pagado, $respuesta->json('data.pagado'), 0.001, 'Pagado incorrecto.');
        $this->assertEqualsWithDelta($saldo, $respuesta->json('data.saldo'), 0.001, 'Saldo incorrecto.');
    }

    // ------------------------------------------------------------------
    // El pedido del revendedor en el ciclo de compra
    // ------------------------------------------------------------------

    public function test_mientras_es_borrador_el_pedido_no_tiene_ciclo(): void
    {
        $pedidoId = $this->borradorDeMaria();

        $this->assertSame('borrador', $this->pedido($pedidoId)->estado);
        $this->assertNull($this->pedido($pedidoId)->ciclo_compra_id);
    }

    public function test_al_enviarlo_entra_al_ciclo_abierto_de_su_distribuidora(): void
    {
        $pedidoId = $this->borradorDeMaria();

        $respuesta = $this->postJson("/api/pedidos/{$pedidoId}/enviar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'colocado');

        $ciclo = CicloCompra::withoutGlobalScopes()->findOrFail($respuesta->json('data.ciclo_compra_id'));

        $this->assertSame($this->distribuidora()->id, (int) $ciclo->distribuidora_id);
        $this->assertSame('abierto', $ciclo->estado);
        $this->assertTrue($ciclo->fecha_cierre > now(), 'El ciclo asignado ya había cerrado.');
        $this->assertNotNull($this->pedido($pedidoId)->fecha_colocacion);
    }

    public function test_la_distribuidora_ve_el_pedido_de_la_revendedora_en_el_ciclo(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $cicloId = $this->pedido($pedidoId)->ciclo_compra_id;

        $this->como('admin@calzadosramirez.test');

        $pedidos = collect($this->getJson("/api/ciclos/{$cicloId}")->assertOk()->json('data.pedidos'));

        $this->assertTrue(
            $pedidos->contains(fn ($p) => $p['id'] === $pedidoId && $p['tipo'] === 'revendedor'),
            'El pedido de la revendedora no aparece en el detalle del ciclo.'
        );
    }

    public function test_dos_pedidos_enviados_antes_del_cierre_caen_en_el_mismo_ciclo(): void
    {
        $primero = $this->pedidoEnviadoDeMaria();
        $segundo = $this->pedidoEnviadoDeMaria();

        $this->assertSame($this->pedido($primero)->ciclo_compra_id, $this->pedido($segundo)->ciclo_compra_id);
    }

    public function test_un_pedido_enviado_despues_del_cierre_entra_al_ciclo_siguiente(): void
    {
        $primero = $this->pedidoEnviadoDeMaria();
        $cicloAnterior = CicloCompra::withoutGlobalScopes()->findOrFail($this->pedido($primero)->ciclo_compra_id);

        $this->travelTo($cicloAnterior->fecha_cierre->copy()->addMinute());

        $segundo = $this->pedidoEnviadoDeMaria();
        $cicloNuevo = CicloCompra::withoutGlobalScopes()->findOrFail($this->pedido($segundo)->ciclo_compra_id);

        $this->assertNotSame($cicloAnterior->id, $cicloNuevo->id);
        $this->assertTrue($cicloNuevo->fecha_cierre > $cicloAnterior->fecha_cierre);
    }

    public function test_no_se_puede_enviar_un_pedido_sin_lineas(): void
    {
        $pedidoId = $this->pedidoVacioDeMaria();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertStatus(422);

        $this->assertNull($this->pedido($pedidoId)->ciclo_compra_id);
    }

    public function test_un_pedido_ya_enviado_no_se_vuelve_a_enviar(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $ciclo = $this->pedido($pedidoId)->ciclo_compra_id;

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertStatus(422);

        $this->assertSame($ciclo, $this->pedido($pedidoId)->ciclo_compra_id);
    }

    // ------------------------------------------------------------------
    // Pagos: parcial y total
    // ------------------------------------------------------------------

    public function test_un_pago_parcial_deja_el_resto_como_saldo(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;
        $this->assertGreaterThan(0, $total);

        $mitad = round($total / 2, 2);

        $this->como(self::EMPLEADO);

        $respuesta = $this->pagar($pedidoId, $mitad)->assertCreated();

        $this->assertPagadoYSaldo($respuesta, $mitad, $total - $mitad);
    }

    public function test_con_el_segundo_pago_queda_liquidado(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;
        $mitad = round($total / 2, 2);

        $this->como(self::EMPLEADO);
        $this->pagar($pedidoId, $mitad)->assertCreated();

        $respuesta = $this->pagar($pedidoId, round($total - $mitad, 2))
            ->assertCreated()
            ->assertJsonCount(2, 'data.pagos');

        $this->assertPagadoYSaldo($respuesta, $total, 0);

        // Ya liquidado, no admite ni un peso más.
        $this->pagar($pedidoId, 1)->assertStatus(422)->assertJsonValidationErrors(['monto']);
    }

    public function test_un_solo_pago_por_el_total_lo_liquida(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;

        $this->como(self::EMPLEADO);

        $respuesta = $this->pagar($pedidoId, $total)->assertCreated();

        $this->assertPagadoYSaldo($respuesta, $total, 0);
    }

    public function test_la_revendedora_ve_en_la_app_lo_que_ya_pago(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;
        $mitad = round($total / 2, 2);

        $this->como(self::EMPLEADO);
        $this->pagar($pedidoId, $mitad)->assertCreated();

        $this->como(self::MARIA);

        $respuesta = $this->getJson("/api/pedidos/{$pedidoId}")->assertOk();

        $this->assertPagadoYSaldo($respuesta, $mitad, $total - $mitad);
    }

    // ------------------------------------------------------------------
    // Pagos que no se aceptan
    // ------------------------------------------------------------------

    public function test_no_se_puede_pagar_mas_que_el_saldo(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;

        $this->como(self::EMPLEADO);

        $this->pagar($pedidoId, $total + 1)->assertStatus(422)->assertJsonValidationErrors(['monto']);
        $this->assertSame(0, $this->pedido($pedidoId)->pagos()->count());
    }

    public function test_un_pago_en_cero_no_se_acepta(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $this->como(self::EMPLEADO);

        $this->pagar($pedidoId, 0)->assertStatus(422)->assertJsonValidationErrors(['monto']);
    }

    public function test_un_borrador_no_se_puede_cobrar(): void
    {
        $pedidoId = $this->borradorDeMaria();

        $this->como(self::EMPLEADO);

        $this->pagar($pedidoId, 10)->assertStatus(422)->assertJsonValidationErrors(['pedido']);
    }

    public function test_un_pedido_rechazado_no_se_puede_cobrar(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $this->como(self::EMPLEADO);
        app(CambiarEstadoPedidoService::class)->cambiar($this->pedido($pedidoId), 'rechazado');

        $this->pagar($pedidoId, 10)->assertStatus(422)->assertJsonValidationErrors(['pedido']);
    }

    /** El anticipo es solo para pedidos de cliente directo. */
    public function test_a_un_pedido_de_revendedor_no_se_le_cobra_anticipo(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();

        $this->como(self::EMPLEADO);

        $this->pagar($pedidoId, 10, 'anticipo')->assertStatus(422)->assertJsonValidationErrors(['tipo']);
    }

    /** Cobrar es solo del mostrador: la revendedora no puede marcar su pedido como pagado. */
    public function test_la_revendedora_no_puede_registrar_pagos(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) $this->pedido($pedidoId)->total;

        $this->pagar($pedidoId, $total)->assertStatus(403);
        $this->assertSame(0, $this->pedido($pedidoId)->pagos()->count());
    }
}
