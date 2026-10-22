<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\Auditoria;
use App\Models\ConteoInventario;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Services\Auth\PermisoModuloService;
use App\Services\Inventario\ConteoInventarioService;
use App\Exceptions\OperacionInvalidaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Inventario físico: captura, diferencias y aplicar ajustes con bitácora. */
class ConteoInventarioTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    /** Dos variantes con existencia en la sucursal principal. */
    private function dosConStock(): array
    {
        $stocks = StockLocal::query()->where('cantidad_disponible', '>=', 3)->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $stocks, 'El seeder no dejó existencias suficientes.');

        return $stocks->all();
    }

    public function test_contar_y_aplicar_deja_el_stock_igual_a_lo_contado_con_movimientos_y_bitacora(): void
    {
        $this->comoPanel(self::ADMIN);
        [$a, $b] = $this->dosConStock();
        $servicio = app(ConteoInventarioService::class);
        $conteo = $servicio->iniciar();
        $esperadoA = (int) $a->cantidad_disponible;
        $esperadoB = (int) $b->cantidad_disponible;

        // A: escáner, dos lecturas → 2 piezas (faltan); B: a mano, 1 de más.
        $sku = Variante::findOrFail($a->variante_id)->sku;
        $servicio->escanear($conteo->id, $sku);
        $servicio->escanear($conteo->id, $sku);
        $renglonB = $conteo->renglones()->where('variante_id', $b->variante_id)->firstOrFail();
        $servicio->fijarContado($conteo->id, $renglonB->id, $esperadoB + 1);

        $this->assertSame($esperadoA, (int) $a->fresh()->cantidad_disponible, 'Contar no toca el stock');
        $resumen = $servicio->resumen($conteo->fresh());
        $this->assertSame(2, $resumen['con_diferencia']);
        $this->assertSame(1, $resumen['sobrantes']);
        $this->assertSame($esperadoA - 2, $resumen['faltantes']);

        $movimientosAntes = MovimientoStock::query()->count();
        $servicio->aplicar($conteo->id);

        $this->assertSame(2, (int) $a->fresh()->cantidad_disponible);
        $this->assertSame($esperadoB + 1, (int) $b->fresh()->cantidad_disponible);
        $this->assertSame($movimientosAntes + 2, MovimientoStock::query()->count());
        $this->assertSame('aplicado', $conteo->fresh()->estado);
        $this->assertTrue(Auditoria::withoutGlobalScopes()->where('accion', 'inventario.conteo_aplicado')->where('entidad_id', $conteo->id)->exists());

        // No se aplica dos veces.
        $this->expectException(OperacionInvalidaException::class);
        $servicio->aplicar($conteo->id);
    }

    public function test_lo_no_contado_no_cambia_y_no_se_permite_contar_negativo(): void
    {
        $this->comoPanel(self::ADMIN);
        [$a] = $this->dosConStock();
        $servicio = app(ConteoInventarioService::class);
        $conteo = $servicio->iniciar();
        $antes = (int) $a->cantidad_disponible;

        $renglon = $conteo->renglones()->where('variante_id', $a->variante_id)->firstOrFail();
        try {
            $servicio->fijarContado($conteo->id, $renglon->id, -1);
            $this->fail('Debió rechazar un conteo negativo.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('negativo', $e->getMessage());
        }

        $servicio->aplicar($conteo->id);
        $this->assertSame($antes, (int) $a->fresh()->cantidad_disponible);
    }

    public function test_aplicar_requiere_permiso_de_ajustar_stock(): void
    {
        $this->staffDe(self::EMPLEADO)->update(['permisos_finos' => [PermisoModuloService::STOCK_AJUSTAR => false]]);
        $this->comoPanel(self::EMPLEADO);
        $servicio = app(ConteoInventarioService::class);
        $conteo = $servicio->iniciar();

        $this->expectException(OperacionInvalidaException::class);
        $servicio->aplicar($conteo->id);
    }

    public function test_otra_distribuidora_no_ve_ni_toca_el_conteo(): void
    {
        $this->comoPanel(self::ADMIN);
        $conteo = app(ConteoInventarioService::class)->iniciar();

        $this->comoPanel(self::OTRA_ADMIN);
        $this->assertNull(ConteoInventario::query()->find($conteo->id));
        try {
            app(ConteoInventarioService::class)->aplicar($conteo->id);
            $this->fail('No debió aplicar un conteo ajeno.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('No se encontró', $e->getMessage());
        }
    }

    public function test_la_pantalla_escanea_y_aplica(): void
    {
        $this->comoPanel(self::ADMIN);
        [$a] = $this->dosConStock();
        $sku = Variante::findOrFail($a->variante_id)->sku;

        $lw = Livewire::test('conteos.index')->call('iniciar')->assertSet('errorMsg', '');
        $lw->set('codigo', $sku)->set('piezas', 4)->call('escanear')->assertSet('errorMsg', '')->assertSet('codigo', '')
            ->set('codigo', 'NO-EXISTE-123')->call('escanear')->assertSee('No encontramos el código')
            ->call('aplicar')->assertSet('errorMsg', '');

        $this->assertSame(4, (int) $a->fresh()->cantidad_disponible);

        $this->get(route('conteos.index'))
            ->assertRedirect(route('stock.index', ['pestana' => 'conteo']));

        $this->get(route('stock.index', ['pestana' => 'conteo']))
            ->assertOk()
            ->assertSee('Nuevo conteo')
            ->assertSee('data-pestana-stock="conteo"', false)
            ->assertDontSee('Inventario físico');
    }

    public function test_sin_permiso_el_conteo_no_sale_en_stock_y_la_ruta_vieja_sigue_cerrada(): void
    {
        $this->staffDe(self::EMPLEADO)
            ->forceFill(['permisos_finos' => [PermisoModuloService::INVENTARIO_CONTEO => false]])
            ->save();
        $this->comoPanel(self::EMPLEADO);

        $this->get(route('conteos.index'))->assertForbidden();

        $this->get(route('stock.index', ['pestana' => 'conteo']))
            ->assertOk()
            ->assertDontSee('data-pestana-stock="conteo"', false)
            ->assertDontSee('Nuevo conteo')
            ->assertSee('Existencias');
    }
}
