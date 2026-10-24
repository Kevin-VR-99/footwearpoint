<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-114 (E7-01): el punto de venta muestra la línea a la que pertenece cada
 * producto. La línea sale de la campaña en la que está publicado (TG-209).
 */
class LineaEnPuntoVentaTest extends TestCase
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

    private function primerRenglon(): array
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon, 'El seeder demo no dejó nada con existencia para vender.');

        return $renglon;
    }

    private function lineaEsperada(array $renglon): string
    {
        return (string) ProductoCampana::withoutGlobalScopes()
            ->with('campana.linea')
            ->findOrFail($renglon['producto_campana_id'])
            ->campana->linea->nombre;
    }

    public function test_cada_renglon_trae_la_linea_de_su_campana(): void
    {
        $disponibles = Livewire::test('punto-venta.index')->instance()->disponibles;
        $this->assertNotEmpty($disponibles);

        foreach ($disponibles as $renglon) {
            $this->assertEquals($this->lineaEsperada($renglon), $renglon['linea']);
        }
    }

    public function test_la_tabla_muestra_la_columna_linea(): void
    {
        $renglon = $this->primerRenglon();

        Livewire::test('punto-venta.index')
            ->assertSeeHtml('>Línea</th>')
            ->assertSee($this->lineaEsperada($renglon));
    }

    public function test_se_puede_buscar_por_linea(): void
    {
        $linea = $this->lineaEsperada($this->primerRenglon());

        $filtrados = Livewire::test('punto-venta.index')
            ->set('busqueda', mb_strtoupper($linea))
            ->instance()->disponibles;

        $this->assertNotEmpty($filtrados);
        foreach ($filtrados as $fila) {
            $this->assertTrue(
                str_contains(mb_strtolower($fila['linea']), mb_strtolower($linea))
                || str_contains(mb_strtolower($fila['nombre'].$fila['sku'].$fila['codigo_catalogo'].$fila['talla'].$fila['color']), mb_strtolower($linea))
            );
        }
    }
}
