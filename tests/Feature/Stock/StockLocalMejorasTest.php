<?php

namespace Tests\Feature\Stock;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Stock\StockService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/** Mejoras de Stock local: entrada por modelo, combobox, movimientos, importación, apartado y tabla agrupada. */
class StockLocalMejorasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->como(self::EMPLEADO);
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
    }

    /** Un producto + color con varias tallas que ya tienen existencia en la sucursal. */
    private function grupoConTallas(): array
    {
        $stock = StockLocal::query()->with('variante')->orderBy('id')->firstOrFail();
        $variantes = Variante::query()
            ->where('producto_id', $stock->variante->producto_id)
            ->where('color_id', $stock->variante->color_id)
            ->orderBy('id')->get();
        $this->assertGreaterThan(1, $variantes->count());

        return [$stock->variante->producto_id, $stock->variante->color_id, $variantes];
    }

    private function existencia(int $varianteId): int
    {
        return (int) StockLocal::query()->where('variante_id', $varianteId)->value('cantidad_disponible');
    }

    // 1) Entrada por modelo

    public function test_entrada_por_modelo_registra_todas_las_tallas_en_una_accion(): void
    {
        [$producto, $color, $variantes] = $this->grupoConTallas();
        [$a, $b] = [$variantes[0], $variantes[1]];
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);
        $movimientosAntes = MovimientoStock::count();

        Livewire::test('stock.index')
            ->set('tabEntrada', 'modelo')
            ->set('modelo_producto_id', (string) $producto)
            ->set('modelo_color_id', (string) $color)
            ->assertSee('Hay '.$antesA)
            ->set('modelo_cantidades', [$a->id => '4', $b->id => '2', ($variantes[2]->id ?? 0) => ''])
            ->set('modelo_motivo', 'Compra a fábrica')
            ->call('registrarEntradaModelo')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Entrada registrada: 6 piezas en 2 tallas.')
            ->assertSee(($antesA).' → '.($antesA + 4));

        $this->assertSame($antesA + 4, $this->existencia($a->id));
        $this->assertSame($antesB + 2, $this->existencia($b->id));
        $this->assertSame($movimientosAntes + 2, MovimientoStock::count());
        $this->assertSame(2, MovimientoStock::where('tipo', 'entrada')->where('motivo', 'Compra a fábrica')->count());
    }

    public function test_entrada_por_modelo_exige_alguna_cantidad_e_ignora_variantes_ajenas_al_modelo(): void
    {
        [$producto, $color, $variantes] = $this->grupoConTallas();
        $otra = Variante::query()->where('producto_id', '!=', $producto)->firstOrFail();
        $antesOtra = $this->existencia($otra->id);

        Livewire::test('stock.index')
            ->set('modelo_producto_id', (string) $producto)
            ->set('modelo_color_id', (string) $color)
            ->set('modelo_cantidades', [$otra->id => '9'])
            ->call('registrarEntradaModelo')
            ->assertHasErrors('modelo_cantidades');

        $this->assertSame($antesOtra, $this->existencia($otra->id));
    }

    public function test_entrada_multiple_es_atomica(): void
    {
        [, , $variantes] = $this->grupoConTallas();
        $antes = $this->existencia($variantes[0]->id);

        try {
            app(StockService::class)->registrarEntradas([$variantes[0]->id => 3, 99999999 => 2]);
            $this->fail('Debió fallar por la variante inexistente.');
        } catch (\App\Exceptions\OperacionInvalidaException) {
        }

        $this->assertSame($antes, $this->existencia($variantes[0]->id));
    }

    public function test_la_entrada_de_una_sola_variante_sigue_funcionando(): void
    {
        $variante = Variante::query()->orderBy('id')->firstOrFail();
        $antes = $this->existencia($variante->id);

        Livewire::test('stock.index')
            ->set('entrada_variante_id', (string) $variante->id)
            ->set('entrada_cantidad', '3')
            ->call('registrarEntrada')
            ->assertHasNoErrors()
            ->assertDispatched('stock-limpiar-variantes');

        $this->assertSame($antes + 3, $this->existencia($variante->id));
    }

    // 2) Combobox

    public function test_el_combobox_busca_por_sku_y_pone_primero_la_coincidencia_exacta(): void
    {
        $variante = Variante::query()->with('producto')->orderBy('id')->firstOrFail();
        $componente = Livewire::test('stock.index');

        $exacto = $componente->instance()->buscarVariantes($variante->sku);
        $this->assertSame($variante->id, $exacto[0]['id']);

        $porNombre = $componente->instance()->buscarVariantes(mb_substr($variante->producto->nombre, 0, 6));
        $this->assertNotEmpty($porNombre);
        $this->assertLessThanOrEqual(20, count($porNombre));

        $this->assertSame([], $componente->instance()->buscarVariantes('   '));
        $this->assertSame([], $componente->instance()->buscarVariantes('NO-EXISTE-XYZ'));
    }

    public function test_la_pagina_ya_no_trae_el_select_largo_de_variantes(): void
    {
        Livewire::test('stock.index')
            ->assertSee('data-combobox-variante="entrada_variante_id"', false)
            ->assertSee('data-combobox-variante="ajuste_variante_id"', false)
            ->assertDontSee('— Seleccionar variante —');
    }

    // 3) Movimientos

    public function test_el_modal_muestra_los_movimientos_de_la_variante(): void
    {
        $stock = StockLocal::query()->orderBy('id')->firstOrFail();
        app(StockService::class)->registrarEntrada($stock->variante_id, 5, 'Llegó de fábrica');
        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 1, 'Merma por daño');

        $componente = Livewire::test('stock.index')
            ->call('verMovimientos', $stock->id)
            ->assertSee('Llegó de fábrica')
            ->assertSee('Merma por daño')
            ->assertSee('Ajuste −')
            ->assertSee('Entrada');

        $this->assertSame(10, $componente->instance()->movimientos->perPage());

        $componente->call('cerrarMovimientos')->assertDontSee('Merma por daño');
    }

    public function test_los_movimientos_se_paginan(): void
    {
        $stock = StockLocal::query()->orderBy('id')->firstOrFail();
        for ($i = 1; $i <= 12; $i++) {
            app(StockService::class)->registrarEntrada($stock->variante_id, 1, "Entrada {$i}");
        }

        $componente = Livewire::test('stock.index')->call('verMovimientos', $stock->id)
            ->assertSee('Entrada 12')->assertDontSee('Entrada 1<', false);
        $componente->call('paginaMovimientos', 2)->assertSee('Entrada 1');
    }

    public function test_no_ve_movimientos_de_otra_distribuidora(): void
    {
        $ajena = StockLocal::withoutGlobalScopes()->where('distribuidora_id', '!=', Tenant::id())->first();
        $this->assertNotNull($ajena);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('stock.index')->call('verMovimientos', $ajena->id);
    }

    // 4) Importar

    private function csv(string $contenido, string $nombre = 'entradas.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    public function test_importar_muestra_vista_previa_con_errores_y_no_aplica(): void
    {
        $variante = Variante::query()->orderBy('id')->firstOrFail();
        $antes = $this->existencia($variante->id);

        Livewire::test('stock.index')
            ->set('tabEntrada', 'importar')
            ->set('archivo', $this->csv("SKU,cantidad,motivo\n{$variante->sku},3,Compra\nNO-EXISTE,2,\n{$variante->sku},0,\n"))
            ->assertSee('SKU desconocido.')
            ->assertSee('La cantidad debe ser un entero mayor que cero.')
            ->call('aplicarImportacion')
            ->assertSet('errorImportacion', 'Corrige las filas con error antes de aplicar.');

        $this->assertSame($antes, $this->existencia($variante->id));
    }

    public function test_importar_suma_duplicados_aplica_en_una_transaccion_y_audita(): void
    {
        [$a, $b] = Variante::query()->orderBy('id')->limit(2)->get()->all();
        $antesA = $this->existencia($a->id);
        $antesB = $this->existencia($b->id);

        Livewire::test('stock.index')
            ->set('archivo', $this->csv("sku;Cantidad;Motivo\n{$a->sku};2;Factura 10\n{$b->sku};5;\n".strtolower($a->sku).";3;\n"))
            ->assertSet('vistaPrevia.errores', 0)
            ->assertSet('vistaPrevia.lineas.0.cantidad', 5)
            ->call('aplicarImportacion')
            ->assertSet('mensaje', 'Importación aplicada: 10 piezas en 2 variantes.')
            ->assertSet('vistaPrevia', null);

        $this->assertSame($antesA + 5, $this->existencia($a->id));
        $this->assertSame($antesB + 5, $this->existencia($b->id));
        $this->assertTrue(MovimientoStock::where('motivo', 'Factura 10')->where('cantidad', 5)->exists());
        $this->assertTrue(MovimientoStock::where('motivo', 'Importación: entradas.csv')->exists());
        $this->assertTrue(Auditoria::query()->where('accion', 'stock.importacion')->exists());
    }

    public function test_importar_acepta_excel(): void
    {
        $variante = Variante::query()->orderBy('id')->firstOrFail();
        $antes = $this->existencia($variante->id);

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getActiveSheet()->fromArray([['SKU', 'cantidad'], [$variante->sku, 7]]);
        $ruta = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

        Livewire::test('stock.index')
            ->set('archivo', UploadedFile::fake()->createWithContent('entradas.xlsx', file_get_contents($ruta)))
            ->assertSet('vistaPrevia.errores', 0)
            ->call('aplicarImportacion');

        $this->assertSame($antes + 7, $this->existencia($variante->id));
    }

    public function test_importar_valida_columnas_tipo_y_limite_de_filas(): void
    {
        $componente = Livewire::test('stock.index');

        $componente->set('archivo', $this->csv("codigo,piezas\nX,1\n"))
            ->assertSet('errorImportacion', 'El archivo debe tener las columnas SKU y cantidad en la primera fila. Descarga la plantilla.');

        $componente->set('archivo', UploadedFile::fake()->createWithContent('foto.png', 'x'))
            ->assertSet('errorImportacion', 'Sube un archivo .xlsx, .xls o .csv.');

        $filas = "SKU,cantidad\n".str_repeat("ABC,1\n", 1001);
        $componente->set('archivo', $this->csv($filas))
            ->assertSet('errorImportacion', 'El archivo tiene más de 1000 filas. Divídelo en partes.');

        $componente->set('archivo', UploadedFile::fake()->create('grande.csv', 3000))
            ->assertHasErrors('archivo');
    }

    public function test_la_plantilla_se_descarga_y_solo_para_el_personal(): void
    {
        $this->get(route('stock.plantilla'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('SKU,cantidad,motivo', false);

        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        auth()->logout();
        $this->get(route('stock.plantilla'))->assertRedirect();
    }

    // 5) Apartado / disponible

    public function test_apartado_cuenta_pedidos_abiertos_sin_tocar_el_stock(): void
    {
        $stock = StockLocal::query()->with('variante')->orderBy('id')->firstOrFail();
        $existencia = (int) $stock->cantidad_disponible;

        $crear = function (string $estado, int $cantidad) use ($stock) {
            $pedido = Pedido::create([
                'distribuidora_id' => Tenant::id(),
                'sucursal_id' => Sucursal::query()->value('id'),
                'folio' => 'AP-'.uniqid(),
                'tipo' => 'cliente_directo',
                'cliente_directo_id' => ClienteDirecto::query()->value('id'),
                'estado' => $estado,
                'subtotal' => 100, 'total' => 100,
                'fecha_colocacion' => now(),
                'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
            ]);
            PedidoDetalle::query()->forceCreate([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id,
                'producto_campana_id' => ProductoCampana::query()->value('id'),
                'variante_id' => $stock->variante_id, 'producto_nombre' => 'X', 'modelo' => 'X',
                'talla' => '25', 'color' => 'Negro', 'cantidad' => $cantidad, 'precio_unitario' => 100, 'subtotal' => 100,
            ]);
        };

        $crear('confirmado', 2);
        $crear('listo_entrega', 1);
        $crear('entregado', 5);   // ya entregado: no cuenta
        $crear('descartado', 4);  // cerrado: no cuenta

        $this->assertSame([$stock->variante_id => 3], app(StockService::class)->enPedidosAbiertos([$stock->variante_id]));
        $this->assertSame($existencia, $this->existencia($stock->variante_id));

        Livewire::test('stock.index')
            ->assertSee('Apartado')
            ->assertSee('Disponible para vender')
            ->assertSee('no apartan ni descuentan este stock');
    }

    // 6) Tabla agrupada

    public function test_la_tabla_agrupa_por_modelo_y_color_con_chips_y_se_expande(): void
    {
        [$producto, $color, $variantes] = $this->grupoConTallas();
        $stock = StockLocal::query()->where('variante_id', $variantes[0]->id)->with('variante.talla')->firstOrFail();
        $clave = $producto.'-'.$color;

        $componente = Livewire::test('stock.index')
            ->assertSee($stock->variante->talla->valor.': '.(int) $stock->cantidad_disponible)
            ->assertDontSee('data-fila-talla', false);

        $grupos = collect($componente->instance()->grupos->items());
        $this->assertSame(1, $grupos->where('producto_id', $producto)->where('color_id', $color)->count());
        $this->assertSame(
            (int) StockLocal::query()->whereIn('variante_id', $variantes->pluck('id'))->sum('cantidad_disponible'),
            (int) $grupos->firstWhere('producto_id', $producto)->total,
        );

        $componente->call('alternarGrupo', $clave)
            ->assertSee('data-fila-talla', false)
            ->assertSee($stock->variante->sku)
            ->assertSee('data-ver-movimientos', false)
            ->call('alternarGrupo', $clave)
            ->assertDontSee('data-fila-talla', false);
    }

    public function test_busqueda_y_solo_bajo_filtran_grupos(): void
    {
        $stock = StockLocal::query()->with('variante.producto')->orderBy('id')->firstOrFail();
        $stock->timestamps = false;
        $stock->forceFill(['cantidad_disponible' => 1, 'stock_minimo' => 3])->save();

        $componente = Livewire::test('stock.index')->set('busqueda', $stock->variante->sku);
        $this->assertSame(1, $componente->instance()->grupos->total());

        $componente->set('busqueda', $stock->variante->producto->nombre);
        $this->assertGreaterThanOrEqual(1, $componente->instance()->grupos->total());

        $componente->set('busqueda', '')->set('soloBajo', true)
            ->assertSee('data-chip-bajo', false)
            ->assertSee($stock->variante->sku);   // solo bajo abre los grupos
        $this->assertSame(1, $componente->instance()->grupos->total());
    }
}
