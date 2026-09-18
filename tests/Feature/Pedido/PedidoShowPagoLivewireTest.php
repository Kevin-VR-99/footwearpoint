<?php

namespace Tests\Feature\Pedido;

use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-158 — Pago desde el detalle Livewire del pedido (panel web).
 *
 * PedidoRevendedorCicloYPagosTest ya cubre el cobro por API. Aquí se pasa
 * por pedidos.show, que es por donde el empleado registra el pago en mostrador.
 */
class PedidoShowPagoLivewireTest extends TestCase
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

    private function comoApi(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoPanel(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

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

    private function pedidoEnviadoDeMaria(): int
    {
        $this->comoApi(self::MARIA);

        $maria = Usuario::where('email', self::MARIA)->firstOrFail();

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $this->distribuidora()->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => 2,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    public function test_el_empleado_registra_un_pago_parcial_desde_el_detalle_livewire(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->total;
        $this->assertGreaterThan(0, $total);
        $mitad = round($total / 2, 2);

        $this->comoPanel(self::EMPLEADO);

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->assertSet('pagoTipo', 'saldo_pedido')
            ->set('pagoMetodo', 'efectivo')
            ->set('pagoMonto', (string) $mitad)
            ->call('registrarPago')
            ->assertSet('errorMsg', '')
            ->assertSet('mensaje', 'Pago registrado.');

        $this->assertSame(1, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
        $this->assertEqualsWithDelta(
            $mitad,
            (float) Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->sum('monto'),
            0.001
        );
    }

    public function test_desde_livewire_no_se_puede_pagar_mas_que_el_saldo(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $total = (float) Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->total;

        $this->comoPanel(self::EMPLEADO);

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoMetodo', 'efectivo')
            ->set('pagoMonto', (string) round($total + 1, 2))
            ->call('registrarPago')
            ->assertSet('mensaje', '')
            ->assertNotSet('errorMsg', '');

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
    }

    public function test_un_borrador_no_se_puede_cobrar_desde_livewire(): void
    {
        $this->comoApi(self::MARIA);
        $maria = Usuario::where('email', self::MARIA)->firstOrFail();

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'revendedor',
            'propietario_id' => Revendedor::where('usuario_id', $maria->id)->value('id'),
            'sucursal_id' => Sucursal::withoutGlobalScopes()
                ->where('distribuidora_id', $this->distribuidora()->id)
                ->where('es_principal', true)
                ->value('id'),
        ])->assertCreated()->json('data.id');

        $variante = $this->variantePedible();
        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => 1,
        ])->assertCreated();

        $this->comoPanel(self::EMPLEADO);

        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->set('pagoMetodo', 'efectivo')
            ->set('pagoMonto', '10')
            ->call('registrarPago')
            ->assertSet('mensaje', '')
            ->assertNotSet('errorMsg', '');

        $this->assertSame(0, Pago::withoutGlobalScopes()->where('pedido_id', $pedidoId)->count());
    }
}
