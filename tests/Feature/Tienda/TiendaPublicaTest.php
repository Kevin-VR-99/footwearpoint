<?php

namespace Tests\Feature\Tienda;

use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoDestacado;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Services\Tienda\TiendaPublica;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-233 (G14) — La tienda pública de una distribuidora (/tienda/{slug}).
 *
 * Con los datos demo: Calzados Ramírez vende Impuls Deportivo e Impuls
 * Escolar, oculta el JQ7143 y le puso precio propio de mayoreo al IR1458102;
 * Boutique del Calzado vende Impuls Deportivo y Confort Dama y no sale en el
 * directorio (marketplace_visible = false).
 */
class TiendaPublicaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const RAMIREZ = 'calzados-ramirez';

    private const BOUTIQUE = 'boutique-del-calzado';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    private function distribuidora(string $slug): Distribuidora
    {
        return Distribuidora::where('slug', $slug)->firstOrFail();
    }

    private function tienda(string $slug = self::RAMIREZ, string $consulta = ''): TestResponse
    {
        return $this->get('/tienda/'.$slug.($consulta !== '' ? '?'.$consulta : ''));
    }

    /** El producto de ese modelo en su temporada activa. */
    private function productoCampana(string $modelo): ProductoCampana
    {
        return ProductoCampana::query()
            ->whereRelation('producto', 'modelo', $modelo)
            ->whereRelation('campana', 'estado', 'activa')
            ->firstOrFail();
    }

    private function enDistribuidora(string $slug, callable $accion): mixed
    {
        return Tenant::forzar($this->distribuidora($slug)->id, $accion);
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** El HTML entre dos marcas (para revisar solo una sección de la página). */
    private function seccion(string $html, string $desde, string $hasta): string
    {
        $inicio = strpos($html, $desde);
        $this->assertNotFalse($inicio, "No está la sección {$desde}");
        $fin = strpos($html, $hasta, $inicio);

        return substr($html, $inicio, $fin === false ? null : $fin - $inicio);
    }

    private function assertSinPalabrasDeMayoreo(string $html): void
    {
        $texto = mb_strtolower($html);

        foreach (['mayoreo', 'mayorista', 'revendedor'] as $palabra) {
            $this->assertStringNotContainsString($palabra, $texto, "La tienda dice \"{$palabra}\"");
        }
    }

    // ---------------------------------------------------------------
    // 1. Sin sesión se ve la tienda con sus datos y lo que vende
    // ---------------------------------------------------------------

    public function test_sin_sesion_se_ve_la_tienda_con_sus_datos_y_lo_que_vende(): void
    {
        $this->tienda()
            ->assertOk()
            ->assertSee('<title>Calzados Ramírez — Tienda | FootwearPoint</title>', false)
            ->assertSee('Calzados Ramírez')
            ->assertSee('Av. Central 123, Comitán, Chiapas')
            ->assertSee('9631234567')
            ->assertSee('contacto@calzadosramirez.test')
            ->assertSee('Lunes a sábado, 9:00 a 19:00')
            // Impuls Deportivo
            ->assertSee('Nike Reax 8 NS SL')
            ->assertSee('Puma Caven 2.0')
            // Impuls Escolar
            ->assertSee('Escolar HGN 468')
            ->assertSee('Escolar Destroyer 1182')
            // Confort Dama es solo de la otra distribuidora.
            ->assertDontSee('Zapato charol tacón 7 cm')
            ->assertDontSee('Mocasín charol cuña 3.5 cm')
            ->assertSee('Pídelo en mostrador o desde la app de FootwearPoint');

        // Y la otra ve lo suyo.
        $this->tienda(self::BOUTIQUE)
            ->assertOk()
            ->assertSee('Zapato charol tacón 7 cm')
            ->assertSee('Puma Caven 2.0')
            ->assertDontSee('Escolar HGN 468');
    }

    // ---------------------------------------------------------------
    // 2. Lo que la distribuidora ocultó no sale, ni en el detalle
    // ---------------------------------------------------------------

    public function test_no_muestra_lo_que_la_distribuidora_oculto(): void
    {
        $oculto = $this->productoCampana('JQ7143');

        $this->tienda()->assertOk()->assertDontSee('Adidas Park St 2.0');
        $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$oculto->id)
            ->assertNotFound()
            ->assertSee('Página no encontrada');

        // La otra distribuidora no lo ocultó: ahí sí sale.
        $this->get('/tienda/'.self::BOUTIQUE.'/productos/'.$oculto->id)
            ->assertOk()
            ->assertSee('Adidas Park St 2.0');
    }

    // ---------------------------------------------------------------
    // 3. Solo el precio de menudeo
    // ---------------------------------------------------------------

    public function test_solo_muestra_el_precio_de_menudeo(): void
    {
        $respuesta = $this->tienda()->assertOk()
            ->assertSee('$2,879.00 MXN')
            ->assertSee('Precio')
            ->assertDontSee('1,990.00')   // precio propio de Ramírez
            ->assertDontSee('2,290.00');  // precio propio de Boutique
        $html = $respuesta->getContent();
        $this->assertSinPalabrasDeMayoreo($html);

        // Ningún precio de mayoreo de lo que vende aparece en la página.
        $this->enDistribuidora(self::RAMIREZ, function () use ($html) {
            $precios = app(PrecioEfectivo::class);
            $precios->olvidarLoConsultado();

            foreach (app(CatalogoVisible::class)->consulta()->get() as $producto) {
                $mayoreo = $precios->mayoreo($producto);
                $this->assertNotSame($mayoreo, $precios->menudeo($producto));
                $this->assertStringNotContainsString('$'.number_format($mayoreo, 2), $html);
            }
        });

        $detalle = $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$this->productoCampana('IR1458102')->id)
            ->assertOk()
            ->assertSee('$2,879.00 MXN')
            ->assertDontSee('1,990.00');
        $this->assertSinPalabrasDeMayoreo($detalle->getContent());
    }

    // ---------------------------------------------------------------
    // 4. Con sesión (de otra distribuidora o de un cliente mayorista) se ve igual
    // ---------------------------------------------------------------

    public function test_con_sesion_de_otra_distribuidora_o_de_un_cliente_mayorista_se_ve_igual(): void
    {
        $boutique = $this->distribuidora(self::BOUTIQUE);

        $this->como('admin@boutiquedelcalzado.test');
        $this->tienda()->assertOk()
            ->assertSee('Escolar HGN 468')
            ->assertDontSee('Zapato charol tacón 7 cm')
            ->assertSee('$2,879.00 MXN')
            ->assertDontSee('2,290.00');
        // Al terminar, su distribuidora vuelve a ser la suya.
        $this->assertSame((int) $boutique->id, Tenant::id());

        $this->como('maria.lopez@revendedor.test');
        $respuesta = $this->tienda()->assertOk()
            ->assertSee('$2,879.00 MXN')
            ->assertDontSee('1,990.00');
        $this->assertSinPalabrasDeMayoreo($respuesta->getContent());

        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        $this->tienda()->assertOk();
        $this->assertNull(Tenant::id());
    }

    // ---------------------------------------------------------------
    // 5. Solo las distribuidoras activas tienen tienda
    // ---------------------------------------------------------------

    public function test_solo_las_distribuidoras_activas_tienen_tienda(): void
    {
        $this->tienda('no-existe')->assertNotFound()->assertSee('Página no encontrada');
        $this->get('/tienda/Calzados_Ramirez')->assertNotFound();

        $ramirez = $this->distribuidora(self::RAMIREZ);

        foreach (['pendiente', 'suspendida', 'rechazada'] as $estado) {
            $ramirez->update(['estado' => $estado]);

            $respuesta = $this->tienda()->assertNotFound()->assertSee('Página no encontrada');
            $this->assertStringNotContainsString('Exception', $respuesta->getContent());
            $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$this->productoCampana('IR1458102')->id)->assertNotFound();
        }

        // marketplace_visible no bloquea la tienda (Boutique no sale en el directorio).
        $this->assertFalse($this->distribuidora(self::BOUTIQUE)->marketplace_visible);
        $this->tienda(self::BOUTIQUE)->assertOk()->assertSee('Boutique del Calzado');
    }

    // ---------------------------------------------------------------
    // 6. Respeta las reglas del catálogo
    // ---------------------------------------------------------------

    public function test_respeta_las_reglas_del_catalogo(): void
    {
        // De temporada finalizada: nunca.
        $this->tienda()->assertDontSee('Puma Smash 3.0');

        // Retirado de la temporada por el admin general.
        $this->productoCampana('39618102')->update(['activo' => false]);
        // Producto inactivo.
        Producto::where('modelo', '1182')->update(['activo' => false]);
        // Sin tallas activas.
        Variante::whereIn('producto_id', Producto::where('modelo', '24293')->select('id'))->update(['activa' => false]);
        // Ninguna talla que se pueda pedir.
        DisponibilidadVarianteCampana::where('producto_campana_id', $this->productoCampana('311')->id)
            ->update(['estado' => 'no_disponible']);

        $this->tienda()->assertOk()
            ->assertDontSee('Puma Caven 2.0')
            ->assertDontSee('Escolar Destroyer 1182')
            ->assertDontSee('Escolar Yuyin 24293')
            ->assertDontSee('Casual HGN 311')
            ->assertSee('Nike Air Max Fire')
            ->assertSee('Algunas tallas bajo pedido');

        // Las tallas no disponibles no se muestran; las de bajo pedido sí, marcadas.
        $tienda = app(TiendaPublica::class);
        $ramirez = $this->distribuidora(self::RAMIREZ);

        $airMax = collect($tienda->producto($ramirez, $this->productoCampana('IR0818007')->id)['tallas']);
        $this->assertSame(['22', '23', '24', '25', '26'], $airMax->pluck('talla')->all());

        $reax = collect($tienda->producto($ramirez, $this->productoCampana('IR1458102')->id)['tallas']);
        $this->assertSame(['26', '27'], $reax->where('bajo_pedido', true)->pluck('talla')->values()->all());
        $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$this->productoCampana('IR1458102')->id)
            ->assertOk()
            ->assertSee('Bajo pedido');

        // La distribuidora desactiva una de sus líneas: desaparece todo lo de esa línea.
        $escolar = Linea::where('nombre', 'Impuls Escolar')->firstOrFail();
        $this->enDistribuidora(self::RAMIREZ, fn () => DistribuidoraLinea::where('linea_id', $escolar->id)->update(['activa' => false]));

        $this->tienda()->assertOk()
            ->assertDontSee('Escolar HGN 468')
            ->assertSee('Nike Reax 8 NS SL');
    }

    // ---------------------------------------------------------------
    // 7. El detalle de un producto que no vende es 404
    // ---------------------------------------------------------------

    public function test_el_detalle_de_un_producto_que_no_vende_es_404(): void
    {
        $confort = $this->productoCampana('355-37');
        $finalizado = ProductoCampana::query()
            ->whereRelation('producto', 'modelo', '38528301')
            ->firstOrFail();

        $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$confort->id)->assertNotFound()->assertSee('Página no encontrada');
        $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$finalizado->id)->assertNotFound();
        $this->get('/tienda/'.self::RAMIREZ.'/productos/999999')->assertNotFound();
        $this->get('/tienda/'.self::RAMIREZ.'/productos/abc')->assertNotFound();

        $reax = $this->productoCampana('IR1458102');
        $this->get('/tienda/'.self::RAMIREZ.'/productos/'.$reax->id)
            ->assertOk()
            ->assertSee('<title>Nike Reax 8 NS SL Blanco/Plata — Calzados Ramírez | FootwearPoint</title>', false)
            ->assertSee('Nike Reax 8 NS SL')
            ->assertSee('Modelo IR1458102')
            ->assertSee('Impuls Deportivo')
            ->assertSee('Volver a la tienda');
    }

    // ---------------------------------------------------------------
    // 8. Filtros por marca, línea y búsqueda
    // ---------------------------------------------------------------

    public function test_filtra_por_marca_linea_y_busqueda(): void
    {
        $puma = Marca::where('nombre', 'Puma')->value('id');
        $escolar = Linea::where('nombre', 'Impuls Escolar')->value('id');

        $this->tienda(consulta: 'marca='.$puma)->assertOk()
            ->assertSee('Puma Caven 2.0')
            ->assertSee('Puma Shuffle Downtown')
            ->assertDontSee('Nike Reax 8 NS SL')
            ->assertDontSee('Escolar HGN 468');

        $this->tienda(consulta: 'linea='.$escolar)->assertOk()
            ->assertSee('Escolar HGN 468')
            ->assertDontSee('Puma Caven 2.0');

        $this->tienda(consulta: 'q=caven')->assertOk()
            ->assertSee('Puma Caven 2.0')
            ->assertDontSee('Nike Reax 8 NS SL');

        // Por código de catálogo.
        $this->tienda(consulta: 'q=889879')->assertOk()->assertSee('Nike Reax 8 NS SL')->assertDontSee('Puma Caven 2.0');

        // Una marca que esta tienda no vende no muestra nada de otra.
        $cklass = Marca::where('nombre', 'Cklass')->value('id');
        $this->tienda(consulta: 'marca='.$cklass)->assertOk()
            ->assertSee('No encontramos productos con esos filtros.')
            ->assertDontSee('Zapato charol tacón 7 cm');

        // Basura en la dirección: no truena.
        $this->tienda(consulta: 'marca=abc&linea=-1&q=%25')->assertOk()->assertSee('Calzados Ramírez');
        $this->tienda(consulta: 'q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);

        // Con los botones de la página.
        Livewire::test('tienda.index', ['slug' => self::RAMIREZ])
            ->call('filtrarMarca', $puma)
            ->assertSet('marca', (string) $puma)
            ->assertSee('Puma Caven 2.0')
            ->assertDontSee('Nike Reax 8 NS SL')
            ->call('filtrarLinea', $escolar)
            ->assertSee('No encontramos productos con esos filtros.')
            ->call('limpiarFiltros')
            ->assertSee('Nike Reax 8 NS SL')
            ->assertSee('Escolar HGN 468');
    }

    // ---------------------------------------------------------------
    // 9. Destacados
    // ---------------------------------------------------------------

    public function test_muestra_los_destacados_de_la_distribuidora_en_su_orden(): void
    {
        $html = $this->tienda()->assertOk()->getContent();
        $destacados = $this->seccion($html, 'id="titulo-destacados"', 'id="titulo-marcas"');

        // Los del seeder demo, en su orden.
        $this->assertMatchesRegularExpression('/Nike Reax 8 NS SL.*Adidas Grand Court Base 3\.0.*Escolar HGN 468/s', $destacados);
        $this->assertStringNotContainsString('Puma Caven 2.0', $destacados);

        // Boutique destaca otro producto que Ramírez también vende: no sale en Ramírez.
        $caven = $this->productoCampana('39618102');
        $this->enDistribuidora(self::BOUTIQUE, fn () => ProductoDestacado::create(['producto_campana_id' => $caven->id, 'orden' => 1]));
        // Ramírez oculta uno de sus destacados: ya no sale.
        $grandCourt = $this->productoCampana('JR4616');
        $this->enDistribuidora(self::RAMIREZ, fn () => app(GestionarOfertaDistribuidoraAction::class)->ocultar($grandCourt->id));

        $destacados = $this->seccion($this->tienda()->getContent(), 'id="titulo-destacados"', 'id="titulo-marcas"');
        $this->assertStringContainsString('Nike Reax 8 NS SL', $destacados);
        $this->assertStringNotContainsString('Adidas Grand Court Base 3.0', $destacados);
        $this->assertStringNotContainsString('Puma Caven 2.0', $destacados);

        $boutique = $this->seccion($this->tienda(self::BOUTIQUE)->getContent(), 'id="titulo-destacados"', 'id="titulo-marcas"');
        $this->assertStringContainsString('Puma Caven 2.0', $boutique);
        $this->assertStringNotContainsString('Nike Reax 8 NS SL', $boutique);

        // Al filtrar no se muestran.
        $this->tienda(consulta: 'q=reax')->assertOk()->assertDontSee('id="titulo-destacados"', false);

        // Sin destacados, no hay sección.
        $this->enDistribuidora(self::RAMIREZ, fn () => ProductoDestacado::query()->delete());
        $this->tienda()->assertOk()
            ->assertDontSee('id="titulo-destacados"', false)
            ->assertSee('Nike Reax 8 NS SL');
    }
}
