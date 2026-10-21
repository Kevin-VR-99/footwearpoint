<?php

namespace Tests\Feature\Funciones;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Exportar\ExportarListadoXlsx;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Exportación .xlsx de pedidos y clientes con los filtros de la pantalla. */
class ExportarListadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
    }

    /** Lee el .xlsx que generó la descarga de Livewire y devuelve sus celdas. */
    private function celdas($componente, string $metodo = 'exportar'): array
    {
        $capturado = null;
        $this->mock(ExportarListadoXlsx::class, function ($m) use (&$capturado) {
            $real = new ExportarListadoXlsx;
            $m->shouldReceive('descargar')->andReturnUsing(function (...$args) use ($real, &$capturado) {
                $capturado = $real->libro($args[1], $args[2], $args[3], $args[4] ?? []);

                return $real->descargar(...$args);
            });
        });
        $componente->call($metodo)->assertFileDownloaded();

        $ruta = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($capturado))->save($ruta);

        return IOFactory::load($ruta)->getActiveSheet()->toArray();
    }

    private function columna(array $celdas, int $i): array
    {
        return array_values(array_filter(array_column($celdas, $i), fn ($v) => $v !== null));
    }

    public function test_pedidos_se_exportan_con_el_filtro_de_tipo_y_solo_del_tenant(): void
    {
        $this->como('admin@calzadosramirez.test');
        $base = [
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => \App\Models\Sucursal::query()->value('id'),
            'estado' => 'colocado', 'subtotal' => 10, 'total' => 10, 'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) \App\Models\DistribuidoraStaff::query()->value('id'),
        ];
        Pedido::create($base + ['folio' => 'XL-MAY', 'tipo' => 'revendedor', 'revendedor_distribuidora_id' => RevendedorDistribuidora::query()->value('id')]);
        Pedido::create($base + ['folio' => 'XL-CLI', 'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::query()->value('id')]);
        $c = Livewire::test('pedidos.index')->set('filtro_tipo', 'revendedor');
        $celdas = $this->celdas($c);

        $folios = Pedido::query()->where('tipo', 'revendedor')->pluck('folio')->all();
        $this->assertNotEmpty($folios);
        $exportados = array_slice($this->columna($celdas, 0), 0);
        foreach ($folios as $f) {
            $this->assertContains($f, $exportados);
        }
        foreach (Pedido::withoutGlobalScopes()->where('distribuidora_id', '!=', Tenant::id())->pluck('folio') as $ajeno) {
            $this->assertNotContains($ajeno, $exportados);
        }
        $this->assertNotContains('XL-CLI', $exportados);
        $this->assertNotContains('Cliente', $this->columna($celdas, 1), 'El filtro de tipo debe excluir clientes directos.');
        $this->assertContains('Tipo: Cliente mayorista', $this->columna($celdas, 0));
    }

    public function test_el_empleado_tambien_exporta_pedidos_porque_ve_la_pantalla(): void
    {
        $this->como('empleado@calzadosramirez.test');
        Livewire::test('pedidos.index')->call('exportar')->assertFileDownloaded();
    }

    public function test_clientes_directos_se_exportan_con_la_busqueda(): void
    {
        $this->como('admin@calzadosramirez.test');
        $uno = ClienteDirecto::query()->orderBy('id')->firstOrFail();
        $celdas = $this->celdas(Livewire::test('distribuidora.clientes')->set('buscar', $uno->nombre));

        $nombres = $this->columna($celdas, 0);
        $this->assertContains($uno->nombre, $nombres);
        foreach (ClienteDirecto::query()->where('nombre', 'not like', '%'.$uno->nombre.'%')->pluck('nombre') as $otro) {
            $this->assertNotContains($otro, $nombres);
        }
        foreach (ClienteDirecto::withoutGlobalScopes()->where('distribuidora_id', '!=', Tenant::id())->pluck('nombre') as $ajeno) {
            $this->assertNotContains($ajeno, $nombres);
        }
    }

    public function test_mayoristas_se_exportan_con_el_estado(): void
    {
        $this->como('admin@calzadosramirez.test');
        $afiliacion = RevendedorDistribuidora::query()->with('revendedor')->firstOrFail();
        $afiliacion->forceFill(['estado' => 'suspendido'])->save();

        $celdas = $this->celdas(Livewire::test('distribuidora.usuarios')->set('filtroEstado', 'suspendido'));
        $this->assertContains($afiliacion->revendedor->nombre, $this->columna($celdas, 0));
        $this->assertSame(['Estado', 'Suspendido'], array_values(array_intersect(['Estado', 'Suspendido'], $this->columna($celdas, 4))));
    }

    public function test_el_empleado_no_puede_exportar_clientes(): void
    {
        $this->como('empleado@calzadosramirez.test');
        $this->get(route('distribuidora.clientes-directos'))->assertForbidden();
        $this->get(route('distribuidora.mayoristas'))->assertForbidden();

        // Aunque llegara al componente, la acción vuelve a revisar el rol.
        Livewire::test('distribuidora.clientes')->call('exportar')->assertForbidden();
        Livewire::test('distribuidora.usuarios')->call('exportar')->assertForbidden();
    }

    public function test_la_otra_distribuidora_solo_exporta_lo_suyo(): void
    {
        $this->como('admin@boutiquedelcalzado.test');
        $otra = Distribuidora::query()->where('slug', 'boutique-del-calzado')->value('id');
        $this->assertSame($otra, Tenant::id());

        $celdas = $this->celdas(Livewire::test('distribuidora.clientes'));
        foreach (ClienteDirecto::withoutGlobalScopes()->where('distribuidora_id', '!=', $otra)->pluck('nombre') as $ajeno) {
            $this->assertNotContains($ajeno, $this->columna($celdas, 0));
        }
    }
}
