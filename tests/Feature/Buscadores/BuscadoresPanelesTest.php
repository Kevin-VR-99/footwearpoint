<?php

namespace Tests\Feature\Buscadores;

use App\Models\Campana;
use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/** Buscadores de las listas largas en los paneles del admin general y de la distribuidora. */
class BuscadoresPanelesTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(Usuario $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
    }

    private function comoAdminGeneral(): void
    {
        $this->como($this->adminGeneral());
    }

    private function comoDistribuidora(string $email = 'admin@calzadosramirez.test'): void
    {
        $this->como(Usuario::where('email', $email)->firstOrFail());
    }

    public function test_catalogo_maestro_filtra_marcas_por_nombre_y_por_linea(): void
    {
        $this->comoAdminGeneral();
        $linea = Linea::create(['nombre' => 'Linea Zafiro Prueba', 'activa' => true]);
        $buscada = Marca::create(['nombre' => 'Marca Quetzal Prueba', 'activa' => true]);
        $porLinea = Marca::create(['nombre' => 'Marca Colibri Prueba', 'activa' => true]);
        $porLinea->lineas()->attach($linea->id);
        Marca::create(['nombre' => 'Marca Ajena Prueba', 'activa' => true]);

        $c = Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->assertSee('Marca Ajena Prueba')
            ->set('buscarMarca', 'quetzal')
            ->assertSee('Marca Quetzal Prueba')
            ->assertDontSee('Marca Ajena Prueba');
        $this->assertSame([$buscada->id], $c->instance()->marcasFiltradas->pluck('id')->all());

        $c->set('buscarMarca', 'Zafiro')->assertSee('Marca Colibri Prueba')->assertDontSee('Marca Quetzal Prueba');
        $c->set('buscarMarca', 'no-existe-xyz')->assertSee('Sin coincidencias.');
    }

    public function test_catalogo_maestro_filtra_lineas_temporadas_productos_y_opciones_de_marca(): void
    {
        $this->comoAdminGeneral();
        Linea::create(['nombre' => 'Linea Buscable Uno', 'activa' => true]);
        $marca = Marca::create(['nombre' => 'Marca Opcion Unica', 'activa' => true]);
        $otra = Marca::firstOrFail();

        $c = Livewire::test('admin.catalogo-maestro')
            ->set('buscarLinea', 'Buscable Uno')
            ->assertSee('Linea Buscable Uno');
        $this->assertCount(1, $c->instance()->lineasFiltradas);

        $temporada = Campana::query()->with('linea')->firstOrFail();
        $c->set('pestana', 'temporadas')->set('buscarTemporada', $temporada->nombre);
        $this->assertTrue($c->instance()->temporadasFiltradas->contains('id', $temporada->id));

        $producto = Producto::query()->whereHas('publicacionesCampana')->firstOrFail();
        $c->set('pestana', 'productos')->set('buscarProducto', $producto->modelo);
        $this->assertTrue($c->instance()->productosFiltrados->every(fn ($f) => $f->producto?->modelo === $producto->modelo
            || str_contains(mb_strtolower(implode(' ', [$f->producto?->nombre, $f->producto?->modelo, $f->producto?->marca?->nombre, $f->codigo_catalogo, $f->campana?->nombre, $f->campana?->linea?->nombre])), mb_strtolower($producto->modelo))));
        $this->assertTrue($c->instance()->productosFiltrados->isNotEmpty());

        // El selector de marca se filtra, pero la ya elegida no desaparece.
        $c->set('producto_marca_id', (string) $otra->id)->set('buscarMarcaOpcion', 'Opcion Unica');
        $ids = $c->instance()->marcasOpciones->pluck('id')->all();
        $this->assertContains($marca->id, $ids);
        $this->assertContains($otra->id, $ids);
        $this->assertLessThan(Marca::count(), count($ids));
    }

    public function test_stock_por_modelo_filtra_el_selector_por_nombre_marca_y_sku(): void
    {
        $this->comoDistribuidora('empleado@calzadosramirez.test');
        $variante = Variante::query()->with('producto.marca')->whereNotNull('sku')->firstOrFail();
        $producto = $variante->producto;
        $total = Livewire::test('stock.index')->set('tabEntrada', 'modelo')->instance()->productosModelo->count();

        $c = Livewire::test('stock.index')->set('tabEntrada', 'modelo')->set('buscarModelo', $variante->sku);
        $this->assertTrue($c->instance()->productosModelo->contains('id', $producto->id));
        $this->assertLessThanOrEqual($total, $c->instance()->productosModelo->count());

        $c->set('buscarModelo', $producto->marca->nombre);
        $this->assertTrue($c->instance()->productosModelo->contains('id', $producto->id));

        $c->set('buscarModelo', 'zzz-sin-coincidencia-zzz');
        $this->assertCount(0, $c->instance()->productosModelo);

        // Con un modelo elegido, sigue en la lista aunque el texto ya no coincida.
        $c->set('buscarModelo', '')->set('modelo_producto_id', (string) $producto->id)->set('buscarModelo', 'zzz-sin-coincidencia-zzz');
        $this->assertSame([$producto->id], $c->instance()->productosModelo->pluck('id')->all());
    }

    public function test_admin_general_busca_distribuidoras(): void
    {
        $this->comoAdminGeneral();
        $d = Distribuidora::query()->firstOrFail();

        Livewire::test('admin.distribuidoras-index')
            ->set('buscar', $d->nombre_comercial)
            ->assertSee($d->nombre_comercial)
            ->set('buscar', 'zzz-ninguna-zzz')
            ->assertSee('Sin coincidencias.');
    }

    public function test_catalogo_de_solo_lectura_busca_marcas_y_temporadas(): void
    {
        $this->comoDistribuidora();
        Marca::create(['nombre' => 'Marca Lectura Buscada', 'activa' => true]);
        Marca::create(['nombre' => 'Marca Lectura Otra', 'activa' => true]);

        Livewire::test('catalogo.marcas')
            ->set('buscar', 'Buscada')
            ->assertSee('Marca Lectura Buscada')
            ->assertDontSee('Marca Lectura Otra');

        $temporada = Campana::query()->firstOrFail();
        $c = Livewire::test('catalogo.campanas')->set('buscar', $temporada->nombre);
        $this->assertTrue($c->instance()->campanas->contains('id', $temporada->id));
        $c->set('buscar', 'zzz-ninguna-zzz');
        $this->assertCount(0, $c->instance()->campanas);
    }

    public function test_pedidos_busca_por_folio_y_cliente(): void
    {
        $this->comoDistribuidora();
        $cliente = \App\Models\ClienteDirecto::query()->firstOrFail();
        $base = [
            'distribuidora_id' => $cliente->distribuidora_id,
            'sucursal_id' => \App\Models\Sucursal::query()->where('distribuidora_id', $cliente->distribuidora_id)->value('id'),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => $cliente->id,
            'estado' => 'colocado',
            'subtotal' => 100,
            'total' => 100,
            'capturado_por_staff_id' => \App\Models\DistribuidoraStaff::query()->where('distribuidora_id', $cliente->distribuidora_id)->value('id'),
        ];
        Pedido::unguarded(fn () => Pedido::create($base + ['folio' => 'PB-BUSCA-001']));
        Pedido::unguarded(fn () => Pedido::create($base + ['folio' => 'PB-OTRO-002']));

        Livewire::test('pedidos.index')
            ->set('buscar', 'BUSCA-001')
            ->assertSee('PB-BUSCA-001')
            ->assertDontSee('PB-OTRO-002')
            ->set('buscar', $cliente->nombre)
            ->assertSee('PB-OTRO-002');
    }
}