<?php

namespace Tests\Feature\Catalogo;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** La foto del catálogo se ve junto al producto en stock, venta local y el pedido. */
class MiniaturaDeProductoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
    }

    public function test_la_foto_principal_sale_junto_al_sku_en_stock_venta_y_pedido(): void
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon);

        $productoId = Variante::query()->whereKey($renglon['variante_id'])->value('producto_id');
        $campanas = ProductoCampana::query()->where('producto_id', $productoId)->pluck('id');
        ProductoImagen::query()->whereIn('producto_campana_id', $campanas)->delete();

        ProductoImagen::create([
            'producto_campana_id' => $renglon['producto_campana_id'],
            'url' => 'https://ejemplo.test/tenis-secundaria.png',
            'orden' => 1,
            'es_principal' => false,
        ]);
        ProductoImagen::create([
            'producto_campana_id' => $renglon['producto_campana_id'],
            'url' => 'https://ejemplo.test/tenis-principal.png',
            'orden' => 9,
            'es_principal' => true,
        ]);

        Livewire::test('stock.index')
            ->assertSee('https://ejemplo.test/tenis-principal.png', false)
            ->assertDontSee('https://ejemplo.test/tenis-secundaria.png', false);

        Livewire::test('punto-venta.index')
            ->assertSee('https://ejemplo.test/tenis-principal.png', false)
            ->assertDontSee('https://ejemplo.test/tenis-secundaria.png', false)
            ->call('agregar', $renglon['clave'])
            ->assertSee('https://ejemplo.test/tenis-principal.png', false);

        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'FOTO-'.uniqid(),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => 'colocado',
            'subtotal' => 100,
            'total' => 100,
            'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);

        PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => Tenant::id(),
            'pedido_id' => $pedido->id,
            'producto_campana_id' => $renglon['producto_campana_id'],
            'variante_id' => $renglon['variante_id'],
            'producto_nombre' => 'Tenis foto',
            'modelo' => 'TF-1',
            'talla' => '25',
            'color' => 'Negro',
            'cantidad' => 1,
            'precio_unitario' => 100,
            'subtotal' => 100,
        ]);

        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->assertSee('https://ejemplo.test/tenis-principal.png', false)
            ->assertDontSee('https://ejemplo.test/tenis-secundaria.png', false)
            ->assertSee('Tenis foto');
    }
}
