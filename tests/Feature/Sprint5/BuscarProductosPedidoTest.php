<?php

namespace Tests\Feature\Sprint5;

use App\Models\DistribuidoraLinea;
use App\Models\OfertaDistribuidora;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BuscarProductosPedidoTest extends TestCase
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

    private function pantalla()
    {
        $usuario = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $afiliacion = Revendedor::withoutGlobalScopes()->where('usuario_id', $usuario->id)
            ->firstOrFail()->afiliaciones()->firstOrFail();

        $pantalla = Livewire::test('pedidos.create')
            ->set('tipo', 'revendedor')
            ->set('propietario_id', (string) $afiliacion->id)
            ->call('crearBorrador')
            ->assertSet('errorMsg', '');
        $linea = $pantalla->instance()->lineas->firstOrFail();

        return $pantalla->set('linea_id', (string) $linea->id);
    }

    public function test_el_borrador_muestra_agregar_pedido_y_busqueda_accesible(): void
    {
        $this->pantalla()
            ->assertSee('2. Agregar pedido')
            ->assertDontSee('Agregar del catálogo')
            ->assertSee('Buscar productos del pedido');
    }

    public function test_busca_nombre_modelo_y_codigo_sin_distinguir_mayusculas(): void
    {
        $pantalla = $this->pantalla();
        $pc = $pantalla->instance()->catalogo->firstOrFail();
        $pc->producto->update(['nombre' => 'Zapato Único S5', 'modelo' => 'Modelo-S5-XYZ']);
        $pc->update(['codigo_catalogo' => 'COD-S5-XYZ']);

        foreach (['  ZAPATO ÚNICO S5  ', 'modelo-s5-xyz', 'cod-s5-xyz'] as $termino) {
            $pantalla->set('busqueda', $termino);
            $this->assertSame([$pc->id], $pantalla->instance()->catalogoFiltrado->pluck('id')->all());
        }
    }

    public function test_sin_coincidencias_no_modifica_el_catalogo_autorizado(): void
    {
        $pantalla = $this->pantalla();
        $ids = $pantalla->instance()->catalogo->pluck('id')->all();
        $this->assertNotEmpty($ids);

        $pantalla->set('busqueda', 'SIN-COINCIDENCIAS-S5-XYZ')
            ->assertSee('No hay productos que coincidan con la búsqueda.');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
        $this->assertSame($ids, $pantalla->instance()->catalogo->pluck('id')->all());

        $pantalla->set('busqueda', '   ');
        $this->assertSame($ids, $pantalla->instance()->catalogoFiltrado->pluck('id')->all());
    }

    public function test_buscar_conserva_seleccion_variante_y_fotos(): void
    {
        $pantalla = $this->pantalla();
        $pc = $pantalla->instance()->catalogo->first(fn ($pc) => $pc->disponibilidadPorVariante->isNotEmpty());
        $this->assertNotNull($pc);
        $variante = $pc->disponibilidadPorVariante->first()->variante_id;
        $pantalla->set('producto_campana_id', (string) $pc->id)->set('variante_id', (string) $variante);
        $fotos = $pantalla->instance()->fotos;

        $pantalla->set('busqueda', 'SIN-COINCIDENCIAS-S5-XYZ')
            ->assertSet('producto_campana_id', (string) $pc->id)
            ->assertSet('variante_id', (string) $variante)
            ->assertSee('Se conserva el producto seleccionado.');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
        $this->assertSame([$pc->id], $pantalla->instance()->opcionesCatalogo->pluck('id')->all());
        $this->assertContains($variante, $pantalla->instance()->variantesDisponibles->pluck('variante_id'));
        $this->assertSame($fotos, $pantalla->instance()->fotos);
    }

    public function test_cambiar_linea_reinicia_busqueda_y_seleccion(): void
    {
        $pantalla = $this->pantalla();
        $pc = $pantalla->instance()->catalogo->firstOrFail();
        $pantalla->set('producto_campana_id', (string) $pc->id)
            ->set('variante_id', '123')
            ->set('busqueda', 'zapato')
            ->set('linea_id', '')
            ->assertSet('busqueda', '')
            ->assertSet('producto_campana_id', '')
            ->assertSet('variante_id', '');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
    }

    public function test_buscar_no_reintroduce_productos_inactivos_ni_ocultos_para_el_tenant(): void
    {
        $pantalla = $this->pantalla();
        $pc = $pantalla->instance()->catalogo->firstOrFail();
        $pc->producto->update(['nombre' => 'Exclusivo-S5-XYZ']);
        $pc->update(['activo' => false]);
        $pantalla->set('busqueda', 'Exclusivo-S5-XYZ');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);

        $pc->update(['activo' => true]);
        $pc->producto->update(['activo' => false]);
        $pantalla->set('busqueda', 'Exclusivo-S5');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);

        $pc->producto->update(['activo' => true]);
        OfertaDistribuidora::updateOrCreate([
            'producto_campana_id' => $pc->id,
            'distribuidora_id' => Tenant::id(),
        ], ['publicado' => false]);
        $pantalla->set('busqueda', 'Exclusivo-S5-XYZ');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
    }

    public function test_buscar_no_ofrece_otra_linea_ni_lineas_no_asociadas(): void
    {
        $pantalla = $this->pantalla();
        $pc = $pantalla->instance()->catalogo->firstOrFail();
        $pc->producto->update(['nombre' => 'Linea-S5-XYZ']);
        $pantalla->set('linea_id', '999999')->set('busqueda', 'Linea-S5-XYZ');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);

        DistribuidoraLinea::query()->where('linea_id', $pc->campana->linea_id)->delete();
        $pantalla->set('linea_id', (string) $pc->campana->linea_id)->set('busqueda', 'Linea-S5-XYZ');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
    }
}
