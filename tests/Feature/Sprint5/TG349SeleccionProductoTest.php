<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\OfertaDistribuidora;
use App\Models\PedidoDetalle;
use App\Models\ProductoImagen;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TG349SeleccionProductoTest extends TestCase
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

    private function escenario(): array
    {
        $grupos = app(CatalogoVisible::class)->consulta()->with(['producto', 'campana', 'disponibilidadPorVariante'])
            ->orderBy('id')->get()->groupBy('campana.linea_id');
        $grupo = $grupos->sortByDesc(fn ($grupo) => $grupo->count())->first();
        $this->assertGreaterThan(1, $grupo->count());
        $alfa = $grupo->first();
        $beta = $grupo->last();
        foreach (['ALFA' => $alfa, 'BETA' => $beta] as $nombre => $pc) {
            $pc->producto->update(['nombre' => 'Producto '.$nombre.' TG349']);
            $pc->update(['codigo_catalogo' => 'TG349-COD-'.$nombre]);
            ProductoImagen::create([
                'producto_campana_id' => $pc->id, 'url' => 'http://localhost/tg349-'.strtolower($nombre).'.svg',
                'es_principal' => true, 'orden' => 0,
            ]);
            $pc->disponibilidadPorVariante->firstOrFail()->update(['estado' => 'disponible']);
        }
        $pantalla = Livewire::test('pedidos.create')
            ->set('propietario_id', (string) ClienteDirecto::value('id'))
            ->call('crearBorrador')->assertSet('errorMsg', '')
            ->set('linea_id', (string) $beta->campana->linea_id);

        return [$pantalla, $alfa, $beta];
    }

    private function seleccionRenderizada($pantalla): array
    {
        $dom = new \DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$pantalla->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        $xpath = new \DOMXPath($dom);
        $select = $xpath->query('//select[@id="create-producto-campana-id"]')->item(0);
        $seleccionadas = $xpath->query('./option[@selected]', $select);
        $this->assertCount(1, $seleccionadas);

        return [$seleccionadas->item(0)->getAttribute('value'), $select->getAttribute('wire:key')];
    }

    public function test_beta_al_final_conserva_producto_variante_y_foto_al_filtrar_y_restaurar(): void
    {
        [$pantalla, $alfa, $beta] = $this->escenario();
        $variante = (string) $beta->disponibilidadPorVariante->firstOrFail()->variante_id;
        $pantalla->set('producto_campana_id', (string) $beta->id)->set('variante_id', $variante);
        [$valorInicial, $keyInicial] = $this->seleccionRenderizada($pantalla);
        $this->assertSame((string) $beta->id, $valorInicial);

        foreach (['SIN-COINCIDENCIAS-TG349', '', 'TG349-COD-ALFA', ''] as $busqueda) {
            $pantalla->set('busqueda', $busqueda)
                ->assertSet('producto_campana_id', (string) $beta->id)
                ->assertSet('variante_id', $variante)
                ->assertSee('http://localhost/tg349-beta.svg', false);
            [$valor, $key] = $this->seleccionRenderizada($pantalla);
            $this->assertSame((string) $beta->id, $valor);
            $this->assertContains((int) $variante, $pantalla->instance()->variantesDisponibles->pluck('variante_id'));
            $this->assertSame('http://localhost/tg349-beta.svg', $pantalla->instance()->fotos[$beta->id]);
            if ($busqueda === '') {
                $this->assertSame($keyInicial, $key);
            } else {
                $this->assertNotSame($keyInicial, $key);
            }
        }
        $this->assertSame(0, PedidoDetalle::count());
    }

    public function test_cambiar_producto_o_linea_limpia_la_variante_anterior(): void
    {
        [$pantalla, $alfa, $beta] = $this->escenario();
        $pantalla->set('producto_campana_id', (string) $beta->id)
            ->set('variante_id', (string) $beta->disponibilidadPorVariante->firstOrFail()->variante_id)
            ->set('producto_campana_id', (string) $alfa->id)
            ->assertSet('variante_id', '')
            ->assertSee('http://localhost/tg349-alfa.svg', false);
        [$valor] = $this->seleccionRenderizada($pantalla);
        $this->assertSame((string) $alfa->id, $valor);
        $pantalla->set('variante_id', (string) $alfa->disponibilidadPorVariante->firstOrFail()->variante_id)
            ->set('busqueda', 'ALFA')->set('linea_id', '')
            ->assertSet('producto_campana_id', '')->assertSet('variante_id', '')->assertSet('busqueda', '');
        [$valor] = $this->seleccionRenderizada($pantalla);
        $this->assertSame('', $valor);
    }

    public function test_producto_oculto_para_el_tenant_no_se_ofrece_ni_se_agrega_con_id_inyectado(): void
    {
        [$pantalla, $alfa, $beta] = $this->escenario();
        OfertaDistribuidora::updateOrCreate([
            'distribuidora_id' => Tenant::id(), 'producto_campana_id' => $beta->id,
        ], ['publicado' => false]);
        $pantalla->set('busqueda', 'TG349-COD-BETA');
        $this->assertCount(0, $pantalla->instance()->catalogoFiltrado);
        $this->assertNotContains($beta->id, $pantalla->instance()->opcionesCatalogo->pluck('id'));
        $pantalla->set('producto_campana_id', (string) $beta->id)
            ->set('variante_id', (string) $beta->disponibilidadPorVariante->firstOrFail()->variante_id)
            ->call('agregarLinea');
        $this->assertNotSame('', $pantalla->instance()->errorMsg);
        $this->assertSame(0, PedidoDetalle::count());
    }
}
