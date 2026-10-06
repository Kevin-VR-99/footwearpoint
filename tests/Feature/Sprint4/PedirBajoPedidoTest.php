<?php

namespace Tests\Feature\Sprint4;

use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
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
 * TG-214 (Ola 2, K7) — Se puede pedir lo que está "bajo pedido".
 *
 * Un pedido no sale del mostrador: se le pide a la fábrica en el ciclo de
 * compra. Por eso una talla "bajo pedido" sí se puede pedir, solo tarda más.
 * Lo único que se bloquea es lo que la fábrica ya no da ("no disponible") y
 * lo que ni siquiera está registrado en esa temporada.
 */
class PedirBajoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoMaria(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', self::MARIA)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Deja una talla del catálogo con el estado que pida la prueba. */
    private function conEstado(string $estado): DisponibilidadVarianteCampana
    {
        $disponibilidad = DisponibilidadVarianteCampana::query()->orderBy('id')->firstOrFail();
        $disponibilidad->update(['estado' => $estado]);

        return $disponibilidad->fresh();
    }

    private function pedidoDeMaria(): int
    {
        $this->comoMaria();

        return (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');
    }

    private function agregar(int $pedidoId, DisponibilidadVarianteCampana $disponibilidad)
    {
        return $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $disponibilidad->producto_campana_id,
            'variante_id' => $disponibilidad->variante_id,
            'cantidad' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // Por la app
    // ------------------------------------------------------------------

    public function test_una_talla_bajo_pedido_si_se_puede_pedir(): void
    {
        $disponibilidad = $this->conEstado('bajo_pedido');
        $pedidoId = $this->pedidoDeMaria();

        $this->agregar($pedidoId, $disponibilidad)->assertCreated();

        $this->assertSame(1, Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->detalle()->count());
    }

    public function test_una_talla_disponible_se_sigue_pudiendo_pedir(): void
    {
        $disponibilidad = $this->conEstado('disponible');
        $pedidoId = $this->pedidoDeMaria();

        $this->agregar($pedidoId, $disponibilidad)->assertCreated();
    }

    public function test_una_talla_no_disponible_se_rechaza_con_un_mensaje_claro(): void
    {
        $disponibilidad = $this->conEstado('no_disponible');
        $pedidoId = $this->pedidoDeMaria();

        $this->agregar($pedidoId, $disponibilidad)
            ->assertStatus(422)
            ->assertJsonPath('errors.variante_id.0', 'Esa talla no está disponible por ahora.');

        $this->assertSame(0, Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->detalle()->count());
    }

    /** El mensaje no le enseña al cliente los nombres internos del sistema. */
    public function test_el_mensaje_no_muestra_el_estado_tecnico(): void
    {
        $disponibilidad = $this->conEstado('no_disponible');
        $pedidoId = $this->pedidoDeMaria();

        $mensaje = $this->agregar($pedidoId, $disponibilidad)->json('errors.variante_id.0');

        $this->assertStringNotContainsString('no_disponible', $mensaje);
        $this->assertStringNotContainsString('estado:', $mensaje);
    }

    public function test_una_talla_sin_disponibilidad_registrada_tampoco_se_puede_pedir(): void
    {
        $disponibilidad = $this->conEstado('bajo_pedido');
        $productoCampanaId = $disponibilidad->producto_campana_id;
        $varianteId = $disponibilidad->variante_id;
        $disponibilidad->delete();

        $pedidoId = $this->pedidoDeMaria();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $productoCampanaId,
            'variante_id' => $varianteId,
            'cantidad' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.variante_id.0', 'Esa talla no está disponible por ahora.');
    }

    // ------------------------------------------------------------------
    // Desde el panel
    // ------------------------------------------------------------------

    public function test_la_pantalla_de_pedidos_ofrece_las_tallas_bajo_pedido(): void
    {
        $bajoPedido = $this->conEstado('bajo_pedido');

        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $maria = Revendedor::where('email', self::MARIA)->firstOrFail();
        $afiliacion = $maria->afiliaciones()->firstOrFail();

        $pantalla = Livewire::test('pedidos.create')
            ->set('tipo', 'revendedor')
            ->set('propietario_id', (string) $afiliacion->id)
            ->set('sucursal_id', (string) Sucursal::where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))->where('es_principal', true)->value('id'))
            ->call('crearBorrador')
            ->set('linea_id', (string) $bajoPedido->productoCampana->campana->linea_id)
            ->set('producto_campana_id', (string) $bajoPedido->producto_campana_id);

        // La talla bajo pedido aparece, marcada como tal.
        $pantalla->assertSee('Bajo pedido');

        $pantalla->set('variante_id', (string) $bajoPedido->variante_id)
            ->set('cantidad', '2')
            ->call('agregarLinea')
            ->assertSet('errorMsg', '');
    }

    public function test_la_pantalla_de_pedidos_no_ofrece_las_tallas_no_disponibles(): void
    {
        $noDisponible = $this->conEstado('no_disponible');

        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $pantalla = Livewire::test('pedidos.create')
            ->set('linea_id', (string) $noDisponible->productoCampana->campana->linea_id)
            ->set('producto_campana_id', (string) $noDisponible->producto_campana_id);

        $ofrecidas = $pantalla->instance()->variantesDisponibles->pluck('variante_id');

        $this->assertNotContains($noDisponible->variante_id, $ofrecidas);
    }
}
