<?php

namespace Tests\Feature\Funciones;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Busqueda\BusquedaGlobalService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Búsqueda global (Ctrl+K): grupos, enlaces, aislamiento por distribuidora y permisos. */
class BusquedaGlobalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $u = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($u);
        Tenant::olvidarCache();

        return $u;
    }

    private function pedido(string $folio): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => $folio, 'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => 'colocado', 'subtotal' => 99, 'total' => 99, 'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
    }

    public function test_el_navbar_muestra_el_buscador_con_atajo(): void
    {
        $this->como('admin@calzadosramirez.test');
        $this->get(route('pedidos.index'))->assertOk()
            ->assertSee('data-busqueda-global', false)
            ->assertSee('Ctrl')
            ->assertSee('ctrl.k', false)->assertSee('meta.k', false);
    }

    public function test_agrupa_pedidos_clientes_mayoristas_productos_y_variantes(): void
    {
        $this->como('admin@calzadosramirez.test');
        $pedido = $this->pedido('BUSQ-777');
        $cliente = ClienteDirecto::query()->firstOrFail();
        $mayorista = RevendedorDistribuidora::query()->with('revendedor')->firstOrFail();
        $stock = app(\App\Services\Stock\StockService::class)->consultar()->with('variante.producto')->firstOrFail();

        $s = app(BusquedaGlobalService::class);
        $u = Usuario::where('email', 'admin@calzadosramirez.test')->first();

        $r = $s->buscar($u, 'BUSQ-777');
        $this->assertSame(route('pedidos.show', $pedido->id), $r['pedidos']['items'][0]['url']);

        $r = $s->buscar($u, $cliente->nombre);
        $this->assertContains($cliente->nombre, array_column($r['clientes']['items'], 'titulo'));

        $r = $s->buscar($u, $mayorista->revendedor->nombre);
        $this->assertContains($mayorista->revendedor->nombre, array_column($r['mayoristas']['items'], 'titulo'));

        $r = $s->buscar($u, $stock->variante->sku);
        $this->assertContains($stock->variante->sku, array_column($r['variantes']['items'], 'titulo'));
        $this->assertStringContainsString('busqueda=', $r['variantes']['items'][0]['url']);
        $this->assertArrayHasKey('productos', $r);

        Livewire::test('busqueda.global')->set('q', 'BUSQ-777')
            ->assertSee('data-grupo="pedidos"', false)->assertSee('BUSQ-777')
            ->set('q', 'zzzz-no-existe')->assertSee('data-sin-resultados', false)
            ->set('q', 'B')->assertSee('al menos 2');
    }

    public function test_el_empleado_no_ve_clientes_pero_si_pedidos(): void
    {
        $this->como('admin@calzadosramirez.test');
        $this->pedido('BUSQ-EMP');
        $cliente = ClienteDirecto::query()->firstOrFail();

        $empleado = $this->como('empleado@calzadosramirez.test');
        $s = app(BusquedaGlobalService::class);

        $this->assertArrayHasKey('pedidos', $s->buscar($empleado, 'BUSQ-EMP'));
        $r = $s->buscar($empleado, $cliente->nombre);
        $this->assertArrayNotHasKey('clientes', $r);
        $this->assertArrayNotHasKey('mayoristas', $r);
    }

    public function test_no_mezcla_distribuidoras(): void
    {
        $this->como('admin@calzadosramirez.test');
        $this->pedido('BUSQ-RAMIREZ');
        $cliente = ClienteDirecto::query()->firstOrFail();

        $otro = $this->como('admin@boutiquedelcalzado.test');
        $this->assertSame(Distribuidora::where('slug', 'boutique-del-calzado')->value('id'), Tenant::id());
        $s = app(BusquedaGlobalService::class);

        $this->assertSame([], $s->buscar($otro, 'BUSQ-RAMIREZ'));
        $this->assertNotContains($cliente->nombre, array_column($s->buscar($otro, $cliente->nombre)['clientes']['items'] ?? [], 'titulo'));
        Livewire::test('busqueda.global')->set('q', 'BUSQ-RAMIREZ')->assertDontSee('BUSQ-RAMIREZ</span>', false)->assertSee('data-sin-resultados', false);
    }

    public function test_quien_no_es_personal_no_tiene_buscador(): void
    {
        $cliente = $this->como('jose.hernandez@cliente.test');
        $this->assertSame([], app(BusquedaGlobalService::class)->buscar($cliente, 'PED'));
        Livewire::test('busqueda.global')->assertDontSee('data-busqueda-global', false);
    }

    public function test_los_enlaces_de_clientes_llegan_filtrados(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = ClienteDirecto::query()->orderBy('id')->firstOrFail();
        $otro = ClienteDirecto::query()->where('nombre', 'not like', '%'.$cliente->nombre.'%')->firstOrFail();

        Livewire::withQueryParams(['buscar' => $cliente->nombre])->test('distribuidora.clientes')
            ->assertSee($cliente->nombre)->assertDontSee($otro->nombre);
    }
}
