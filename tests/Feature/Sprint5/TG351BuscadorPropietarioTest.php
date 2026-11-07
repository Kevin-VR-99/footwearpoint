<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class TG351BuscadorPropietarioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_busca_clientes_por_nombre_con_mayusculas_y_espacios(): void
    {
        $cliente = ClienteDirecto::firstOrFail();
        $cliente->update(['nombre' => 'Único TG351 Minorista']);
        $pantalla = Livewire::test('pedidos.create')
            ->assertSee('Buscar propietario del pedido')
            ->set('busquedaPropietario', '  ÚNICO TG351  ');

        $this->assertSame([$cliente->id], $pantalla->instance()->propietariosFiltrados->pluck('id')->all());
        $pantalla->assertSee($cliente->nombre);
    }

    public function test_busca_mayoristas_por_el_nombre_de_su_revendedor(): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor')->firstOrFail();
        $afiliacion->revendedor->update(['nombre' => 'Mayorista TG351 Exclusivo']);
        $pantalla = Livewire::test('pedidos.create')->set('tipo', 'revendedor')
            ->set('busquedaPropietario', 'MAYORISTA TG351');

        $this->assertSame([$afiliacion->id], $pantalla->instance()->propietariosFiltrados->pluck('id')->all());
    }

    public function test_busqueda_vacia_restaura_todos_y_sin_coincidencias_informa(): void
    {
        $pantalla = Livewire::test('pedidos.create');
        $ids = $pantalla->instance()->propietarios->pluck('id')->all();
        $this->assertNotEmpty($ids);
        $pantalla->set('busquedaPropietario', 'SIN-COINCIDENCIAS-TG351')
            ->assertSee('No hay propietarios que coincidan con la búsqueda.');
        $this->assertCount(0, $pantalla->instance()->propietariosFiltrados);
        $this->assertSame($ids, $pantalla->instance()->propietarios->pluck('id')->all());
        $pantalla->set('busquedaPropietario', '   ');
        $this->assertSame($ids, $pantalla->instance()->propietariosFiltrados->pluck('id')->all());
    }

    public function test_filtrar_no_borra_la_seleccion_de_cliente_ni_mayorista(): void
    {
        foreach (['cliente_directo', 'revendedor'] as $tipo) {
            $pantalla = Livewire::test('pedidos.create')->set('tipo', $tipo);
            $id = $pantalla->instance()->propietarios->firstOrFail()->id;
            $pantalla->set('propietario_id', (string) $id)
                ->set('busquedaPropietario', 'SIN-COINCIDENCIAS-TG351')
                ->assertSet('propietario_id', (string) $id)
                ->assertSee('Se conserva el propietario seleccionado.');
            $this->assertCount(0, $pantalla->instance()->propietariosFiltrados);
            $this->assertSame([$id], $pantalla->instance()->opcionesPropietario->pluck('id')->all());
        }
    }

    private function seleccionRenderizada($pantalla): array
    {
        $dom = new \DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$pantalla->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        $xpath = new \DOMXPath($dom);
        $select = $xpath->query('//select[@id="create-propietario-id"]')->item(0);
        $seleccionadas = $xpath->query('./option[@selected]', $select);
        $this->assertCount(1, $seleccionadas);

        return [$seleccionadas->item(0)->getAttribute('value'), $select->getAttribute('wire:key')];
    }

    public function test_respuestas_mantienen_opcion_selected_al_filtrar_y_restaurar_en_ambos_tipos(): void
    {
        $cliente = ClienteDirecto::create(['distribuidora_id' => Tenant::id(), 'nombre' => 'ZZ Seleccionado TG351']);
        $revendedor = Revendedor::create(['nombre' => 'ZZ Mayorista seleccionado TG351', 'estado' => 'activo']);
        $afiliacion = RevendedorDistribuidora::create([
            'distribuidora_id' => Tenant::id(), 'revendedor_id' => $revendedor->id,
            'codigo_interno' => 'TG351-REORDEN', 'estado' => 'activo', 'fecha_alta' => now(),
        ]);

        foreach (['cliente_directo' => $cliente->id, 'revendedor' => $afiliacion->id] as $tipo => $id) {
            $pantalla = Livewire::test('pedidos.create')->set('tipo', $tipo)->set('propietario_id', (string) $id);
            [$valorInicial, $keyInicial] = $this->seleccionRenderizada($pantalla);
            $this->assertSame((string) $id, $valorInicial);
            $this->assertGreaterThan(1, $pantalla->instance()->opcionesPropietario->count());

            $pantalla->set('busquedaPropietario', 'SIN-COINCIDENCIAS-TG351')->assertSet('propietario_id', (string) $id);
            [$valorFiltrado, $keyFiltrada] = $this->seleccionRenderizada($pantalla);
            $this->assertSame((string) $id, $valorFiltrado);
            $this->assertNotSame($keyInicial, $keyFiltrada);
            $this->assertCount(1, $pantalla->instance()->opcionesPropietario);

            $pantalla->set('busquedaPropietario', '')->assertSet('propietario_id', (string) $id);
            [$valorRestaurado, $keyRestaurada] = $this->seleccionRenderizada($pantalla);
            $this->assertSame((string) $id, $valorRestaurado);
            $this->assertSame($keyInicial, $keyRestaurada);
            $this->assertGreaterThan(1, $pantalla->instance()->opcionesPropietario->count());
        }
    }

    public function test_cambiar_tipo_limpia_busqueda_y_propietario(): void
    {
        Livewire::test('pedidos.create')
            ->set('propietario_id', (string) ClienteDirecto::value('id'))
            ->set('busquedaPropietario', 'cliente')
            ->set('tipo', 'revendedor')
            ->assertSet('propietario_id', '')
            ->assertSet('busquedaPropietario', '');
    }

    public function test_selector_sincroniza_la_seleccion_y_recrea_el_buscador_por_tipo(): void
    {
        Livewire::test('pedidos.create')
            ->assertSee('wire:model.live="propietario_id"', false)
            ->assertSee('wire:key="selector-propietario-cliente_directo"', false)
            ->set('tipo', 'revendedor')
            ->assertSee('wire:key="selector-propietario-revendedor"', false);
    }

    public function test_cambio_de_tipo_limpia_actualizaciones_agrupadas_en_ambos_ordenes(): void
    {
        $cliente = ClienteDirecto::forceCreate([
            'id' => 7001, 'distribuidora_id' => Tenant::id(), 'nombre' => 'Cliente Alfa colisión TG351',
        ]);
        $revendedor = Revendedor::create(['nombre' => 'Mayorista Alfa colisión TG351', 'estado' => 'activo']);
        $afiliacion = RevendedorDistribuidora::forceCreate([
            'id' => 7001, 'distribuidora_id' => Tenant::id(), 'revendedor_id' => $revendedor->id,
            'codigo_interno' => 'TG351-COLISION', 'estado' => 'activo', 'fecha_alta' => now(),
        ]);
        $this->assertSame($cliente->id, $afiliacion->id);

        foreach ([false, true] as $invertir) {
            foreach ([['cliente_directo', 'revendedor'], ['revendedor', 'cliente_directo']] as [$antes, $despues]) {
                $pantalla = Livewire::test('pedidos.create')->set('tipo', $antes)
                    ->set('busquedaPropietario', 'Alfa');
                // Un solo update HTTP, no set(array), que envía peticiones separadas.
                $updates = ['tipo' => $despues, 'propietario_id' => '7001', 'busquedaPropietario' => 'Alfa'];
                $pantalla->update(updates: $invertir ? array_reverse($updates, true) : $updates)
                    ->assertSet('tipo', $despues)
                    ->assertSet('propietario_id', '')
                    ->assertSet('busquedaPropietario', '')
                    ->call('crearBorrador')
                    ->assertHasErrors('propietario_id')
                    ->assertSet('pedidoId', null);
            }
        }
        $this->assertSame(0, Pedido::count());
    }

    public function test_crear_borrador_conserva_el_id_del_cliente_seleccionado(): void
    {
        $cliente = ClienteDirecto::firstOrFail();
        $pantalla = Livewire::test('pedidos.create')->set('propietario_id', (string) $cliente->id)
            ->set('busquedaPropietario', $cliente->nombre)->call('crearBorrador')->assertSet('errorMsg', '');
        $pedido = Pedido::findOrFail($pantalla->instance()->pedidoId);
        $this->assertSame($cliente->id, $pedido->cliente_directo_id);
        $this->assertNull($pedido->revendedor_distribuidora_id);
    }

    public function test_crear_mayorista_usa_id_de_afiliacion_no_de_revendedor(): void
    {
        $revendedor = Revendedor::forceCreate(['id' => 9001, 'nombre' => 'Mayorista ID distinto TG351', 'estado' => 'activo']);
        $afiliacion = RevendedorDistribuidora::create([
            'distribuidora_id' => Tenant::id(), 'revendedor_id' => $revendedor->id,
            'codigo_interno' => 'TG351-IDS', 'estado' => 'activo', 'fecha_alta' => now(),
        ]);
        $this->assertNotSame($revendedor->id, $afiliacion->id);
        $pantalla = Livewire::test('pedidos.create')->set('tipo', 'revendedor')
            ->set('busquedaPropietario', 'Mayorista ID distinto TG351')
            ->set('propietario_id', (string) $afiliacion->id)
            ->call('crearBorrador')->assertSet('errorMsg', '');
        $pedido = Pedido::findOrFail($pantalla->instance()->pedidoId);
        $this->assertSame($afiliacion->id, $pedido->revendedor_distribuidora_id);
        $this->assertNull($pedido->cliente_directo_id);
    }

    public function test_cliente_ajeno_no_aparece_y_el_servidor_rechaza_su_id(): void
    {
        $otroTenant = Distribuidora::where('id', '!=', Tenant::id())->firstOrFail();
        $ajeno = ClienteDirecto::create(['distribuidora_id' => $otroTenant->id, 'nombre' => 'Cliente ajeno TG351']);
        $pantalla = Livewire::test('pedidos.create')->set('busquedaPropietario', $ajeno->nombre);
        $this->assertCount(0, $pantalla->instance()->propietariosFiltrados);
        $pantalla->set('propietario_id', (string) $ajeno->id)->call('crearBorrador')
            ->assertSet('pedidoId', null)
            ->assertSet('errorMsg', 'El cliente directo no existe en esta distribuidora.');
    }

    public function test_afiliacion_ajena_no_aparece_y_el_servidor_rechaza_su_id(): void
    {
        $otroTenant = Distribuidora::where('id', '!=', Tenant::id())->firstOrFail();
        $revendedor = Revendedor::create(['nombre' => 'Mayorista ajeno TG351', 'estado' => 'activo']);
        $ajena = RevendedorDistribuidora::create([
            'distribuidora_id' => $otroTenant->id, 'revendedor_id' => $revendedor->id,
            'codigo_interno' => 'TG351-AJENO', 'estado' => 'activo', 'fecha_alta' => now(),
        ]);
        $pantalla = Livewire::test('pedidos.create')->set('tipo', 'revendedor')
            ->set('busquedaPropietario', 'Mayorista ajeno TG351');
        $this->assertCount(0, $pantalla->instance()->propietariosFiltrados);
        $pantalla->set('propietario_id', (string) $ajena->id)->call('crearBorrador')
            ->assertSet('pedidoId', null)
            ->assertSet('errorMsg', 'La afiliación de cliente mayorista no existe en esta distribuidora.');
    }

    public function test_el_cliente_autenticado_no_puede_suplantar_un_propietario(): void
    {
        $otroId = ClienteDirecto::value('id');
        $usuario = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $afiliacion = RevendedorDistribuidora::whereHas('revendedor', fn ($q) => $q->where('usuario_id', $usuario->id))->firstOrFail();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $id = $this->postJson('/api/pedidos', ['tipo' => 'cliente_directo', 'propietario_id' => $otroId])
            ->assertCreated()->json('data.id');
        $pedido = Pedido::findOrFail($id);
        $this->assertSame($afiliacion->id, $pedido->revendedor_distribuidora_id);
        $this->assertNull($pedido->cliente_directo_id);
    }
}
