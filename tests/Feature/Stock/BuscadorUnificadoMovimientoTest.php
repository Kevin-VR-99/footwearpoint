<?php

namespace Tests\Feature\Stock;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Registrar movimiento»: un solo buscador (modelo, marca, línea, SKU o código)
 * con sugerencias, color y tallas como recuadros de selección múltiple, y una
 * cantidad por talla marcada. Sigue registrando por StockService.
 */
class BuscadorUnificadoMovimientoTest extends TestCase
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

        return [(int) $stock->variante->producto_id, (int) $stock->variante->color_id, $variantes];
    }

    private function elegir(int $producto, int $color)
    {
        return Livewire::test('stock.index')->call('elegirModelo', $producto)->call('elegirColor', $color);
    }

    public function test_al_escribir_unas_letras_salen_las_sugerencias_de_modelos(): void
    {
        [$producto] = $this->grupo();
        $modelo = Producto::query()->findOrFail($producto);

        $c = Livewire::test('stock.index')
            ->assertDontSee('data-sugerencias-modelo', false)
            ->set('buscarModelo', mb_substr($modelo->nombre, 0, 1))
            ->assertDontSee('data-sugerencias-modelo', false)
            ->set('buscarModelo', mb_substr($modelo->nombre, 0, 3))
            ->assertSee('data-sugerencias-modelo', false)
            ->assertSee('data-sugerencia-modelo', false)
            ->assertSee($modelo->nombre);

        $this->assertTrue($c->instance()->productosModelo->contains('id', $producto));
        $this->assertLessThanOrEqual(8, $c->instance()->productosModelo->count());

        $c->set('buscarModelo', 'zzz-sin-coincidencia-zzz')
            ->assertSee('data-sin-sugerencias', false)
            ->assertSee('No encontramos modelos');
    }

    public function test_busca_por_marca_modelo_sku_y_linea(): void
    {
        [$producto] = $this->grupo();
        $modelo = Producto::query()->with('marca')->findOrFail($producto);
        $sku = Variante::query()->where('producto_id', $producto)->value('sku');
        $c = Livewire::test('stock.index');

        foreach (array_filter([$modelo->marca?->nombre, $modelo->modelo, $sku]) as $texto) {
            $c->set('buscarModelo', $texto);
            $this->assertTrue($c->instance()->productosModelo->contains('id', $producto), 'No sugirió el modelo con «'.$texto.'».');
        }

        $publicacion = ProductoCampana::query()->with('campana.linea')->whereHas('campana.linea')
            ->whereIn('producto_id', Variante::query()->select('producto_id'))->first();
        $this->assertNotNull($publicacion, 'La demo debería traer modelos en una línea.');
        $c->set('buscarModelo', $publicacion->campana->linea->nombre);
        $this->assertTrue($c->instance()->productosModelo->contains('id', $publicacion->producto_id));
        $c->set('buscarModelo', $publicacion->codigo_catalogo);
        $this->assertTrue($c->instance()->productosModelo->contains('id', $publicacion->producto_id));
    }

    public function test_un_sku_exacto_aparece_primero_y_preselecciona_color_y_talla(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        $variante = $variantes[1];

        $c = Livewire::test('stock.index')
            ->set('buscarModelo', $variante->sku)
            ->assertSee('data-sugerencia-sku', false);

        $c->call('elegirSku', $variante->id)
            ->assertSet('modelo_producto_id', (string) $producto)
            ->assertSet('modelo_color_id', (string) $color)
            ->assertSet('buscarModelo', '')
            ->assertSet('modelo_cantidades', [$variante->id => ''])
            ->assertSee('data-modelo-elegido', false)
            ->assertSeeHtml('data-talla-chip="'.$variante->id.'"');

        $this->assertStringContainsString('aria-pressed="true"', $c->html());
    }

    public function test_enter_elige_el_sku_exacto_o_el_unico_modelo_que_coincide(): void
    {
        [$producto, $color, $variantes] = $this->grupo();

        Livewire::test('stock.index')
            ->set('buscarModelo', $variantes[0]->sku)
            ->call('entrarBusqueda')
            ->assertSet('modelo_producto_id', (string) $producto)
            ->assertSet('modelo_color_id', (string) $color);

        $unico = Producto::query()->whereHas('variantes')->get()
            ->first(fn ($p) => Producto::query()->whereHas('variantes')->where('nombre', 'like', '%'.$p->nombre.'%')->count() === 1);
        $this->assertNotNull($unico);

        Livewire::test('stock.index')
            ->set('buscarModelo', $unico->nombre)
            ->call('entrarBusqueda')
            ->assertSet('modelo_producto_id', (string) $unico->id);
    }

    public function test_elegir_modelo_y_color_muestra_las_tallas_como_recuadros_de_seleccion_multiple(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        [$a, $b] = [$variantes[0], $variantes[1]];

        $c = Livewire::test('stock.index')
            ->call('elegirModelo', $producto)
            ->assertSet('modelo_producto_id', (string) $producto)
            ->assertSee('data-colores-modelo', false)
            ->call('elegirColor', $color)
            ->assertSee('data-selector-tallas', false);

        foreach ($variantes as $v) {
            $c->assertSeeHtml('data-talla-chip="'.$v->id.'"');
        }

        $c->call('alternarTalla', $a->id)
            ->call('alternarTalla', $b->id)
            ->assertSet('modelo_cantidades', [$a->id => '', $b->id => ''])
            ->assertSee('data-rejilla-tallas', false)
            ->call('alternarTalla', $a->id)
            ->assertSet('modelo_cantidades', [$b->id => ''])
            ->call('marcarTodasLasTallas');
        $this->assertCount($c->instance()->tallasModelo->count(), $c->get('modelo_cantidades'));

        $c->call('quitarTallas')->assertSet('modelo_cantidades', [])->assertDontSee('data-rejilla-tallas', false);

        // Una variante de otro modelo no se puede marcar.
        $ajena = Variante::query()->where('producto_id', '!=', $producto)->firstOrFail();
        $c->call('alternarTalla', $ajena->id)->assertSet('modelo_cantidades', []);
    }

    public function test_cambiar_de_modelo_limpia_color_y_tallas(): void
    {
        [$producto, $color, $variantes] = $this->grupo();

        Livewire::test('stock.index')
            ->call('elegirModelo', $producto)
            ->call('elegirColor', $color)
            ->call('alternarTalla', $variantes[0]->id)
            ->call('limpiarModelo')
            ->assertSet('modelo_producto_id', '')
            ->assertSet('modelo_color_id', '')
            ->assertSet('modelo_cantidades', [])
            ->assertDontSee('data-modelo-elegido', false)
            ->assertSee('data-buscador-modelo', false);
    }

    public function test_registra_la_entrada_de_las_tallas_marcadas_con_su_historial_por_talla(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        [$a, $b, $sinMarcar] = [$variantes[0], $variantes[1], $variantes[2] ?? null];
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);
        $antesSin = $sinMarcar ? $this->existencia($sinMarcar->id) : 0;
        $movimientos = MovimientoStock::count();

        $c = $this->elegir($producto, $color)
            ->call('alternarTalla', $a->id)
            ->call('alternarTalla', $b->id)
            ->set('modelo_cantidades.'.$a->id, '5')
            ->set('modelo_cantidades.'.$b->id, '2')
            ->set('modelo_motivo', 'Compra a fábrica')
            ->call('registrarEntradaModelo')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Entrada registrada: 7 piezas en 2 tallas.')
            ->assertSet('modelo_cantidades', []);

        $this->assertSame($antesA + 5, $this->existencia($a->id));
        $this->assertSame($antesB + 2, $this->existencia($b->id));
        if ($sinMarcar) {
            $this->assertSame($antesSin, $this->existencia($sinMarcar->id), 'La talla sin marcar no cambia.');
        }
        $this->assertSame($movimientos + 2, MovimientoStock::count());

        $stockA = StockLocal::where('variante_id', $a->id)->firstOrFail();
        $c->call('verMovimientos', $stockA->id)->assertSee('Compra a fábrica');
        $this->assertSame('entrada', MovimientoStock::where('stock_local_id', $stockA->id)->orderByDesc('id')->value('tipo'));
    }

    public function test_registra_la_salida_de_las_tallas_marcadas_y_exige_motivo(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        [$a, $b] = [$variantes[0], $variantes[1]];
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);

        $c = $this->elegir($producto, $color)
            ->set('tipoMovimiento', 'salida')
            ->call('alternarTalla', $a->id)
            ->call('alternarTalla', $b->id)
            ->set('modelo_cantidades.'.$a->id, '2')
            ->set('modelo_cantidades.'.$b->id, '1')
            ->assertSeeHtml('Cantidad que sale de cada talla marcada')
            ->call('registrarEntradaModelo')
            ->assertHasErrors(['modelo_motivo' => 'required']);

        $this->assertSame($antesA, $this->existencia($a->id));

        $c->set('modelo_motivo', 'Merma por daño')
            ->call('registrarEntradaModelo')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Salida registrada: 3 piezas en 2 tallas.');

        $this->assertSame($antesA - 2, $this->existencia($a->id));
        $this->assertSame($antesB - 1, $this->existencia($b->id));
        $this->assertSame(2, MovimientoStock::where('tipo', 'ajuste_negativo')->where('motivo', 'Merma por daño')->count());
    }

    public function test_una_talla_marcada_sin_cantidad_o_sin_ninguna_talla_no_registra_nada(): void
    {
        [$producto, $color, $variantes] = $this->grupo();
        [$a, $b] = [$variantes[0], $variantes[1]];
        $antes = $this->existencia($a->id);
        $movimientos = MovimientoStock::count();

        $c = $this->elegir($producto, $color)
            ->call('registrarEntradaModelo')
            ->assertHasErrors('modelo_cantidades')
            ->assertSee('Marca al menos una talla');

        $c->call('alternarTalla', $a->id)->call('alternarTalla', $b->id)
            ->set('modelo_cantidades.'.$a->id, '3')
            ->call('registrarEntradaModelo')
            ->assertHasErrors('modelo_cantidades')
            ->assertSee('Escribe la cantidad (mínimo 1) de cada talla marcada');

        $this->assertSame($antes, $this->existencia($a->id));
        $this->assertSame($movimientos, MovimientoStock::count());
    }

    public function test_sin_modelo_pide_elegirlo_y_los_obligatorios_llevan_asterisco(): void
    {
        $c = Livewire::test('stock.index')
            ->call('registrarEntradaModelo')
            ->assertHasErrors(['modelo_producto_id' => 'required'])
            ->assertSee('Busca y elige el modelo.');

        $this->assertStringContainsString('fp-campo-requerido', $c->html());

        [$producto, $color] = $this->grupo();
        $salida = $this->elegir($producto, $color)->set('tipoMovimiento', 'salida')->html();
        $this->assertMatchesRegularExpression('/Color <span class="fp-campo-requerido"/', $salida);
        $this->assertMatchesRegularExpression('/Tallas <span class="fp-campo-requerido"/', $salida);
        $this->assertMatchesRegularExpression('/Motivo <span class="fp-campo-requerido"/', $salida);
    }
}
