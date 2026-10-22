<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\Campana;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\MovimientoStock;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\VentaDirecta;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E6-03 (TG-113) — "Al confirmarse una venta directa, la cantidad disponible
 * se reduce de inmediato", probado por el punto de venta WEB, que es por
 * donde vende el empleado en el mostrador.
 *
 * VentaDirectaTest ya lo prueba por la API (POST /api/ventas-directas). Las
 * dos entradas usan el mismo RegistrarVentaDirectaService, pero el punto de
 * venta tiene su propia revisión previa de existencias y hasta ahora ninguna
 * prueba pasaba por él.
 */
class PuntoVentaWebTest extends TestCase
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

    /**
     * El primer renglón que el propio punto de venta ofrece para vender, con
     * su fila de stock_local.
     *
     * @return array{0: array, 1: StockLocal}
     */
    private function primerRenglonDisponible(): array
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();

        $this->assertNotNull($renglon, 'El seeder demo no dejó nada con existencia para vender.');

        $stock = StockLocal::withoutGlobalScopes()
            ->where('variante_id', $renglon['variante_id'])
            ->firstOrFail();

        return [$renglon, $stock];
    }

    public function test_cobrar_en_el_punto_de_venta_descuenta_la_existencia_de_inmediato(): void
    {
        [$renglon, $stock] = $this->primerRenglonDisponible();
        $antes = (int) $stock->cantidad_disponible;
        $this->assertGreaterThanOrEqual(2, $antes);

        $componente = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->call('agregar', $renglon['clave'])
            ->set('metodo_pago', 'efectivo')
            ->call('cobrar')
            ->assertSet('errorMsg', '')
            ->assertSet('lineas', []);

        $this->assertStringStartsWith('Venta VD-', $componente->get('mensaje'));

        // En la misma operación del cobro, sin ningún paso aparte.
        $this->assertSame($antes - 2, (int) $stock->fresh()->cantidad_disponible);

        $venta = VentaDirecta::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('completada', $venta->estado);

        $this->assertDatabaseHas('movimientos_stock', [
            'stock_local_id'       => $stock->id,
            'tipo'                 => 'venta',
            'cantidad'             => 2,
            'existencia_anterior'  => $antes,
            'existencia_posterior' => $antes - 2,
        ]);
    }

    /**
     * Si la existencia se acabó entre que se agregó al carrito y el cobro
     * (otra caja vendió la última pieza), no se vende ni se descuenta nada.
     */
    public function test_si_la_existencia_se_acabo_antes_de_cobrar_no_se_descuenta_nada(): void
    {
        [$renglon, $stock] = $this->primerRenglonDisponible();

        $componente = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave']);

        // Otra caja se lleva todo mientras tanto.
        $stock->forceFill(['cantidad_disponible' => 0])->save();

        $ventasAntes = VentaDirecta::withoutGlobalScopes()->count();
        $movimientosAntes = MovimientoStock::withoutGlobalScopes()->count();

        $componente->set('metodo_pago', 'efectivo')->call('cobrar');

        $this->assertStringContainsString('cambió', $componente->get('errorMsg'));
        $this->assertSame(0, (int) $stock->fresh()->cantidad_disponible);
        $this->assertSame($ventasAntes, VentaDirecta::withoutGlobalScopes()->count());
        $this->assertSame($movimientosAntes, MovimientoStock::withoutGlobalScopes()->count());
    }

    public function test_el_buscador_esta_listo_para_el_lector(): void
    {
        Livewire::test('punto-venta.index')
            ->assertSee('data-pos-escaner')
            ->assertSee('pos-listo-para-escanear')
            ->assertSee('Enter agrega una pieza');
    }

    public function test_enter_con_sku_exacto_agrega_una_pieza_y_limpia_la_busqueda(): void
    {
        [$renglon] = $this->primerRenglonDisponible();

        $componente = Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', '  '.mb_strtoupper($renglon['sku']).'  ')
            ->assertSet('busqueda', '')
            ->assertSet('errorMsg', '')
            ->assertSet('aviso', '')
            ->assertDispatched('pos-listo-para-escanear');

        $linea = collect($componente->get('lineas'))->sole();
        $this->assertSame($renglon['sku'], $linea['sku']);
        $this->assertSame(1, $linea['cantidad']);
        $this->assertSame($renglon['clave'], array_key_first($componente->get('lineas')));
    }

    public function test_el_segundo_escaneo_suma_otra_pieza(): void
    {
        [$renglon] = $this->primerRenglonDisponible();
        $this->assertGreaterThanOrEqual(2, $renglon['disponible']);

        $componente = Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', $renglon['sku'])
            ->call('agregarPorCodigo', $renglon['sku']);

        $this->assertSame(2, collect($componente->get('lineas'))->sole()['cantidad']);
    }

    public function test_enter_vacio_no_llena_la_venta(): void
    {
        Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', '   ')
            ->assertSet('lineas', [])
            ->assertSet('busqueda', '')
            ->assertNotDispatched('pos-listo-para-escanear');
    }

    public function test_enter_sin_existencia_no_agrega(): void
    {
        Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', 'SKU-QUE-NO-EXISTE-XYZ')
            ->assertSet('lineas', [])
            ->assertSet('busqueda', 'SKU-QUE-NO-EXISTE-XYZ')
            ->assertSee('No hay existencia con ese código.');
    }

    public function test_enter_con_varias_coincidencias_no_elige_solo(): void
    {
        $texto = $this->textoAmbiguo();

        Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', $texto)
            ->assertSet('lineas', [])
            ->assertSet('busqueda', trim($texto))
            ->assertSee('Elige');
    }

    public function test_enter_con_el_mismo_sku_en_dos_temporadas_pide_elegir(): void
    {
        [$renglon] = $this->primerRenglonDisponible();
        $this->publicarEnOtraTemporada($renglon);

        Livewire::test('punto-venta.index')
            ->call('agregarPorCodigo', $renglon['sku'])
            ->assertSet('lineas', [])
            ->assertSee('Elige cuál agregar');
    }

    public function test_el_cambio_ayuda_al_cajero_y_no_cambia_el_cobro(): void
    {
        [$renglon] = $this->primerRenglonDisponible();

        $componente = Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->assertSee('data-pos-recibido')
            ->assertSee('No se guarda en la venta')
            ->set('recibido', 'abc')
            ->assertDontSee('data-pos-cambio')
            ->set('recibido', '0,50')
            ->assertSee('Faltan: $')
            ->set('recibido', '9999999')
            ->assertSee('Cambio: $')
            ->set('metodo_pago', 'transferencia')
            ->assertDontSee('data-pos-recibido')
            ->set('metodo_pago', 'efectivo')
            ->call('cobrar')
            ->assertSet('errorMsg', '')
            ->assertSet('recibido', '')
            ->assertSet('lineas', []);

        $this->assertStringContainsString('(efectivo)', $componente->get('mensaje'));

        $venta = VentaDirecta::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta((float) $renglon['precio'], (float) $venta->total, 0.001);
    }

    private function textoAmbiguo(): string
    {
        $filas = Livewire::test('punto-venta.index')->instance()->disponibles;
        $this->assertGreaterThan(1, $filas->count(), 'Hacen falta al menos dos productos para probar una búsqueda ambigua.');

        $candidatos = $filas->flatMap(function (array $fila) {
            $sku = (string) $fila['sku'];
            $corte = mb_strrpos($sku, '-');

            return array_filter([
                $corte === false ? '' : mb_substr($sku, 0, $corte),
                (string) $fila['color'],
                (string) $fila['nombre'],
                (string) $fila['talla'],
            ]);
        })->unique();

        foreach ($candidatos as $texto) {
            $termino = mb_strtolower(trim((string) $texto));

            if ($termino === '') {
                continue;
            }

            $exactos = $filas->filter(fn (array $fila) => mb_strtolower($fila['sku']) === $termino)->count();

            if ($exactos === 1) {
                continue;
            }

            $coinciden = $filas->filter(function (array $fila) use ($termino) {
                return str_contains(mb_strtolower($fila['sku']), $termino)
                    || str_contains(mb_strtolower($fila['codigo_catalogo']), $termino)
                    || str_contains(mb_strtolower($fila['nombre']), $termino)
                    || str_contains(mb_strtolower($fila['talla']), $termino)
                    || str_contains(mb_strtolower($fila['color']), $termino);
            })->count();

            if ($exactos > 1 || $coinciden > 1) {
                return (string) $texto;
            }
        }

        $this->fail('El seeder no dejó un texto que coincida con más de un producto.');
    }

    /** El mismo SKU, publicado en otra línea que esta distribuidora también vende. */
    private function publicarEnOtraTemporada(array $renglon): void
    {
        $publicacion = ProductoCampana::query()->findOrFail($renglon['producto_campana_id']);

        $linea = Linea::create([
            'nombre' => 'Linea escaner '.$publicacion->id.' '.uniqid(),
            'activa' => true,
        ]);

        $campana = Campana::create([
            'linea_id' => $linea->id,
            'nombre' => 'Temporada escaner',
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'estado' => 'activa',
        ]);

        ProductoCampana::create([
            'producto_id' => $publicacion->producto_id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'ESC-'.$publicacion->id.'-'.uniqid(),
            'activo' => true,
            'precio_publico' => $publicacion->precio_publico,
        ]);

        DistribuidoraLinea::create([
            'linea_id' => $linea->id,
            'es_extra' => false,
            'activa' => true,
            'fecha_activacion' => now(),
        ]);
    }
}
