<?php

namespace Tests\Feature\Stock;

use App\Models\ConteoInventario;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Inventario\ConteoInventarioService;
use App\Services\Stock\StockService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regresión del reporte «Conteo y Venta local no muestran las existencias»:
 * todo lo que Stock local tiene en la sucursal tiene que verse también en el
 * Conteo y en Venta local, aunque el producto no esté en el catálogo vigente
 * o su variante esté marcada inactiva.
 */
class ExistenciasVisiblesEnConteoYVentaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    /** Una variante con existencia cuyo producto deja de estar en el catálogo vigente. */
    private function conStockFueraDelCatalogo(): StockLocal
    {
        $stock = StockLocal::query()->with('variante')->where('cantidad_disponible', '>', 0)->orderBy('id')->firstOrFail();
        ProductoCampana::query()->where('producto_id', $stock->variante->producto_id)->update(['activo' => false]);

        return $stock;
    }

    public function test_venta_local_muestra_la_existencia_de_lo_que_no_esta_en_el_catalogo_vigente_sin_poder_cobrarla(): void
    {
        $stock = $this->conStockFueraDelCatalogo();

        $componente = Livewire::test('punto-venta.index');
        $fila = $componente->instance()->disponibles->firstWhere('variante_id', (int) $stock->variante_id);

        $this->assertNotNull($fila, 'La existencia registrada en Stock local debe verse en Venta local.');
        $this->assertTrue($fila['sin_precio']);
        $this->assertSame((int) $stock->cantidad_disponible, $fila['disponible']);

        $componente->assertSee($stock->variante->sku)->assertSee('Sin precio')
            ->call('agregar', $fila['clave'])
            ->assertSet('lineas', [])
            ->assertSee('no está en el catálogo vigente');
    }

    public function test_venta_local_ofrece_lo_que_se_acaba_de_dar_de_entrada_en_stock_local(): void
    {
        $variante = Variante::query()->whereNotIn('id', StockLocal::query()->select('variante_id'))->orderBy('id')->firstOrFail();

        app(StockService::class)->registrarEntrada($variante->id, 6, 'Compra');

        $fila = Livewire::test('punto-venta.index')->instance()->disponibles->firstWhere('variante_id', (int) $variante->id);

        $this->assertNotNull($fila);
        $this->assertSame(6, $fila['disponible']);
    }

    public function test_venta_local_sigue_cobrando_lo_publicado_con_su_precio(): void
    {
        $fila = Livewire::test('punto-venta.index')->instance()->disponibles->first(fn (array $f) => ! $f['sin_precio']);

        $this->assertNotNull($fila);
        $this->assertGreaterThan(0, $fila['precio']);
        Livewire::test('punto-venta.index')->call('agregar', $fila['clave'])->assertSet('errorMsg', '');
    }

    public function test_el_conteo_de_todo_incluye_las_existencias_aunque_el_producto_no_este_en_el_catalogo(): void
    {
        $stock = $this->conStockFueraDelCatalogo();

        $conteo = app(ConteoInventarioService::class)->iniciar();

        $this->assertSame(
            StockLocal::query()->count(),
            $conteo->renglones()->count(),
            'El conteo debe llevar un renglón por cada existencia de Stock local.'
        );
        $this->assertSame((int) $stock->cantidad_disponible, (int) $conteo->renglones()->where('variante_id', $stock->variante_id)->value('esperado'));
    }

    public function test_el_conteo_por_modelo_incluye_la_variante_inactiva_que_tiene_existencia(): void
    {
        $stock = StockLocal::query()->with('variante')->where('cantidad_disponible', '>', 0)->orderBy('id')->firstOrFail();
        Variante::query()->whereKey($stock->variante_id)->update(['activa' => false]);

        $conteo = app(ConteoInventarioService::class)->iniciar($stock->variante->producto_id);

        $renglon = $conteo->renglones()->where('variante_id', $stock->variante_id)->first();
        $this->assertNotNull($renglon, 'Una variante con existencia no puede quedar fuera del conteo del modelo.');
        $this->assertSame((int) $stock->cantidad_disponible, (int) $renglon->esperado);
    }

    public function test_la_pantalla_de_conteo_ofrece_los_modelos_con_existencia(): void
    {
        $stock = $this->conStockFueraDelCatalogo();

        $modelos = Livewire::test('conteos.index')->instance()->modelos;

        $this->assertTrue($modelos->contains('id', $stock->variante->producto_id));
        $this->assertSame(0, ConteoInventario::query()->count());
    }
}
