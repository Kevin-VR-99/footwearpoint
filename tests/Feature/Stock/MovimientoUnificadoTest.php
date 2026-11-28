<?php

namespace Tests\Feature\Stock;

use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stock local: «Registrar entrada» y «Ajuste manual» son ahora un solo
 * movimiento (entrada o salida), sin importar archivo y con talla.
 */
class MovimientoUnificadoTest extends TestCase
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

    private function existencia(int $varianteId): int
    {
        return (int) StockLocal::query()->where('variante_id', $varianteId)->value('cantidad_disponible');
    }

    /** @return array{0:int,1:int,2:\Illuminate\Support\Collection} producto, color y sus tallas con existencia */
    private function grupo(): array
    {
        $stock = StockLocal::query()->with('variante')->where('cantidad_disponible', '>=', 3)->orderBy('id')->firstOrFail();
        $variantes = Variante::query()
            ->where('producto_id', $stock->variante->producto_id)
            ->where('color_id', $stock->variante->color_id)
            ->whereIn('id', StockLocal::query()->select('variante_id'))
            ->orderBy('id')->get();
        $this->assertGreaterThan(1, $variantes->count());

        return [$stock->variante->producto_id, $stock->variante->color_id, $variantes];
    }

    public function test_hay_una_sola_seccion_de_movimiento_sin_importar_archivo_ni_ajuste_manual(): void
    {
        Livewire::test('stock.index')
            ->assertSee('Registrar movimiento')
            ->assertSee('data-tipo-movimiento="entrada"', false)
            ->assertSee('data-tipo-movimiento="salida"', false)
            ->assertSee('Por modelo y talla')
            ->assertDontSee('Importar archivo')
            ->assertDontSee('Descargar plantilla')
            ->assertDontSee('Ajuste manual')
            ->assertDontSee('Registrar ajuste')
            ->assertDontSee('data-combobox-variante="ajuste_variante_id"', false);
    }

    public function test_el_selector_de_variante_ya_no_habla_de_escanear_el_codigo(): void
    {
        $html = Livewire::test('stock.index')->set('tabEntrada', 'variante')->html();

        $this->assertStringContainsString('data-combobox-variante="entrada_variante_id"', $html);
        $this->assertStringNotContainsString('escanea el código', $html);
        $this->assertStringContainsString('placeholder="SKU o modelo"', $html);
    }

    public function test_la_salida_por_modelo_pide_talla_y_descuenta_cada_una_con_su_movimiento(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        [$a, $b] = [$variantes[0], $variantes[1]];
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);
        $movimientosAntes = MovimientoStock::count();

        Livewire::test('stock.index')
            ->set('tipoMovimiento', 'salida')
            ->set('tabEntrada', 'modelo')
            ->set('modelo_producto_id', (string) $producto)
            ->set('modelo_color_id', (string) $color)
            ->assertSee('Cantidad que sale por talla')
            ->set('modelo_cantidades', [$a->id => '2', $b->id => '1'])
            ->set('modelo_motivo', 'Merma por daño')
            ->call('registrarEntradaModelo')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Salida registrada: 3 piezas en 2 tallas.')
            ->assertSee('−2 ('.$antesA.' → '.($antesA - 2).')');

        $this->assertSame($antesA - 2, $this->existencia($a->id));
        $this->assertSame($antesB - 1, $this->existencia($b->id));
        $this->assertSame($movimientosAntes + 2, MovimientoStock::count());
        $this->assertSame(2, MovimientoStock::where('tipo', 'ajuste_negativo')->where('motivo', 'Merma por daño')->count());
    }

    public function test_la_salida_exige_motivo_y_no_deja_la_existencia_en_negativo(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        $a = $variantes[0];
        $antes = $this->existencia($a->id);

        $componente = Livewire::test('stock.index')
            ->set('tipoMovimiento', 'salida')
            ->set('modelo_producto_id', (string) $producto)
            ->set('modelo_color_id', (string) $color)
            ->set('modelo_cantidades', [$a->id => '1'])
            ->call('registrarEntradaModelo')
            ->assertHasErrors(['modelo_motivo' => 'required']);

        $componente->set('modelo_cantidades', [$a->id => (string) ($antes + 5)])
            ->set('modelo_motivo', 'Corrección')
            ->call('registrarEntradaModelo')
            ->assertSet('mensaje', '');
        $this->assertStringContainsString('negativo', $componente->get('errorMsg'));

        $this->assertSame($antes, $this->existencia($a->id), 'Una salida que no alcanza no descuenta nada.');
    }

    public function test_la_salida_de_una_talla_con_otra_que_no_alcanza_es_atomica(): void
    {
        [, , $variantes] = $this->grupo();
        [$a, $b] = [$variantes[0], $variantes[1]];
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);

        try {
            app(\App\Services\Stock\StockService::class)->registrarSalidas([$a->id => 1, $b->id => $antesB + 1], 'Merma');
            $this->fail('Debió rechazar la salida.');
        } catch (\App\Exceptions\OperacionInvalidaException) {
            // esperado
        }

        $this->assertSame($antesA, $this->existencia($a->id));
        $this->assertSame($antesB, $this->existencia($b->id));
    }

    public function test_el_movimiento_por_sku_maneja_entrada_y_salida_y_ambos_quedan_en_el_historial(): void
    {
        $stock = StockLocal::query()->with('variante')->where('cantidad_disponible', '>=', 3)->orderBy('id')->firstOrFail();
        $antes = (int) $stock->cantidad_disponible;

        $c = Livewire::test('stock.index')
            ->set('tabEntrada', 'variante')
            ->set('entrada_variante_id', (string) $stock->variante_id)
            ->set('entrada_cantidad', '4')
            ->set('entrada_motivo', 'Compra a fábrica')
            ->call('registrarEntrada')
            ->assertHasNoErrors();
        $this->assertSame($antes + 4, $this->existencia($stock->variante_id));

        $c->set('tipoMovimiento', 'salida')
            ->set('entrada_variante_id', (string) $stock->variante_id)
            ->set('entrada_cantidad', '3')
            ->call('registrarEntrada')
            ->assertHasErrors(['entrada_motivo' => 'required'])
            ->set('entrada_motivo', 'Merma')
            ->call('registrarEntrada')
            ->assertHasNoErrors();
        $this->assertSame($antes + 1, $this->existencia($stock->variante_id));

        $c->call('verMovimientos', $stock->id)->assertSee('Compra a fábrica')->assertSee('Merma')->assertSee('Ajuste −');
        $this->assertSame(['entrada', 'ajuste_negativo'], MovimientoStock::where('stock_local_id', $stock->id)->orderBy('id')->pluck('tipo')->all());
    }
}
