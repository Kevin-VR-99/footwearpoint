<?php

namespace Tests\Feature\Pedido;

use App\Models\DisponibilidadVarianteCampana;
use App\Models\DispositivoFcm;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Pedido\EntregaPedidoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * TG-169 — Marcar un pedido "listo para entrega" y "entregado" desde la web
 * (corrige E9-05 / E16-01).
 *
 * Antes nada movía un pedido a esos estados: la entrega no se podía
 * confirmar, el push de "listo para entrega" nunca salía y un ciclo con
 * pedidos reales no se podía finalizar.
 *
 * Las pruebas llevan el pedido por el camino real: María lo manda desde la
 * app y el ciclo pasa por fábrica hasta que la mercancía llega.
 */
class EntregaPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    /** Avisos push que habrían salido a Firebase. */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $pushes = &$this->pushes;
        $this->app->instance(EnviadorPush::class, new class($pushes) implements EnviadorPush {
            public function __construct(private array &$pushes) {}

            public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
            {
                $this->pushes[] = compact('tokens', 'titulo', 'mensaje');

                return [];
            }
        });
    }

    private function comoApi(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoPanel(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', self::EMPLEADO)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** María manda su pedido desde la app. */
    private function pedidoDeMaria(): int
    {
        $this->comoApi(self::MARIA);

        $pedidoId = (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');

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
            'cantidad'            => 1,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    /** El ciclo del pedido pasa por fábrica hasta que la mercancía llega a la distribuidora. */
    private function llegaALaDistribuidora(int $pedidoId): int
    {
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->comoApi(self::EMPLEADO);
        foreach (['cerrar', 'solicitar-fabrica', 'marcar-transito', 'marcar-recibido'] as $paso) {
            $this->postJson("/api/ciclos/{$cicloId}/{$paso}")->assertOk();
        }

        $this->assertSame('recibido_distribuidora', $this->pedido($pedidoId)->estado);

        return $cicloId;
    }

    private function pagarTodo(int $pedidoId): void
    {
        $this->comoApi(self::EMPLEADO);

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => 'saldo_pedido',
            'metodo' => 'efectivo',
            'monto'  => (float) $this->pedido($pedidoId)->total,
        ])->assertCreated();
    }

    private function pedido(int $id): Pedido
    {
        return Pedido::withoutGlobalScopes()->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Listo para entrega
    // ------------------------------------------------------------------

    public function test_el_empleado_marca_el_pedido_listo_para_entrega(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->call('marcarListo')
            ->assertSet('errorMsg', '')
            ->assertSee('Pedido listo para entrega');

        $pedido = $this->pedido($pedidoId);
        $this->assertSame('listo_entrega', $pedido->estado);
        $this->assertNotNull($pedido->fecha_listo_entrega);

        // La demo da 5 días para recoger.
        $this->assertSame(now()->addDays(5)->toDateString(), $pedido->fecha_limite_recoleccion->toDateString());
    }

    /** El push que pedía E16-01 y que antes nunca salía. */
    public function test_al_quedar_listo_le_llega_el_aviso_a_la_revendedora(): void
    {
        $maria = Usuario::where('email', self::MARIA)->firstOrFail();
        DispositivoFcm::create([
            'usuario_id'    => $maria->id,
            'token'         => 'celular-de-maria',
            'plataforma'    => 'android',
            'ultimo_uso_at' => now(),
        ]);

        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);
        $this->pushes = [];

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])->call('marcarListo');

        $this->assertCount(1, $this->pushes);
        $this->assertSame(['celular-de-maria'], $this->pushes[0]['tokens']);
        $this->assertStringContainsString('recoger', $this->pushes[0]['mensaje']);
    }

    public function test_un_pedido_que_no_ha_llegado_no_se_marca_listo(): void
    {
        $pedidoId = $this->pedidoDeMaria();

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->call('marcarListo')
            ->assertSet('errorMsg', 'Solo un pedido recibido en la distribuidora se puede marcar listo para entrega.');

        $this->assertSame('colocado', $this->pedido($pedidoId)->estado);
    }

    // ------------------------------------------------------------------
    // Entregado
    // ------------------------------------------------------------------

    public function test_no_se_entrega_con_saldo_pendiente(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->call('marcarListo')
            ->call('marcarEntregado')
            ->assertSee('antes de entregar');

        $this->assertSame('listo_entrega', $this->pedido($pedidoId)->estado);
        $this->assertNull($this->pedido($pedidoId)->fecha_entrega);
    }

    public function test_ya_pagado_se_entrega_y_la_revendedora_lo_ve_en_la_app(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);
        $this->pagarTodo($pedidoId);

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])
            ->call('marcarListo')
            ->call('marcarEntregado')
            ->assertSet('errorMsg', '')
            ->assertSee('Pedido entregado');

        $this->assertNotNull($this->pedido($pedidoId)->fecha_entrega);

        $this->comoApi(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")->assertOk()->assertJsonPath('data.estado', 'entregado');
    }

    /** Si el cliente está en el mostrador cuando llega la mercancía, se le entrega de una vez. */
    public function test_se_puede_entregar_directo_al_recibirlo(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);
        $this->pagarTodo($pedidoId);

        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])->call('marcarEntregado')->assertSet('errorMsg', '');

        $this->assertSame('entregado', $this->pedido($pedidoId)->estado);
    }

    /** La consecuencia más grande: antes ningún ciclo con pedidos reales se podía cerrar del todo. */
    public function test_con_sus_pedidos_entregados_el_ciclo_ya_se_puede_finalizar(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $cicloId = $this->llegaALaDistribuidora($pedidoId);

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/ciclos/{$cicloId}/finalizar")->assertStatus(409);

        $this->pagarTodo($pedidoId);
        $this->comoPanel();
        Livewire::test('pedidos.show', ['id' => $pedidoId])->call('marcarListo')->call('marcarEntregado');

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/ciclos/{$cicloId}/finalizar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'finalizado');
    }

    // ------------------------------------------------------------------
    // Solo el personal
    // ------------------------------------------------------------------

    public function test_la_revendedora_no_puede_marcar_su_propio_pedido_como_entregado(): void
    {
        $pedidoId = $this->pedidoDeMaria();
        $this->llegaALaDistribuidora($pedidoId);
        $this->pagarTodo($pedidoId);

        $this->comoApi(self::MARIA);

        try {
            app(EntregaPedidoAction::class)->marcarEntregado($this->pedido($pedidoId));
            $this->fail('La revendedora pudo marcar su pedido como entregado.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('recibido_distribuidora', $this->pedido($pedidoId)->estado);
    }
}
