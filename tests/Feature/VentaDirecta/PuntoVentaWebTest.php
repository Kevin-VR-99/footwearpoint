<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\MovimientoStock;
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
}
