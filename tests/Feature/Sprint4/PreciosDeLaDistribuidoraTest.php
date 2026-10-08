<?php

namespace Tests\Feature\Sprint4;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\OfertaDistribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\FijarDescuentoMayoristaAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-212 (Ola 2, K5) — Precio de mayoreo y productos ocultos (E4-14).
 *
 * El catálogo y su precio de menudeo son iguales para todas (D8). Lo que cada
 * distribuidora decide es su mayoreo (un descuento general, o un precio propio
 * por producto) y si le esconde algún producto a sus clientes.
 */
class PreciosDeLaDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    private function ofertas(): GestionarOfertaDistribuidoraAction
    {
        return app(GestionarOfertaDistribuidoraAction::class);
    }

    private function precios(): PrecioEfectivo
    {
        // Uno nuevo en cada consulta: el servicio recuerda lo que ya leyó.
        return new PrecioEfectivo();
    }

    private function conDescuentoDe(float $porcentaje): void
    {
        app(FijarDescuentoMayoristaAction::class)->ejecutar($porcentaje);
    }

    /** El producto de la temporada demo, de una línea que la distribuidora ya vende. */
    private function productoDelCatalogo(float $precioPublico = 1000): ProductoCampana
    {
        $campana = Campana::where('estado', 'activa')->firstOrFail();
        $this->asegurarQueVendeLaLinea((int) $campana->linea_id);

        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'MOD-PRECIO-'.uniqid(),
            'nombre' => 'Producto para precios',
            'activo' => true,
        ]);

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'PRE-'.uniqid(),
            'precio_publico' => $precioPublico,
            'activo' => true,
        ]);
    }

    private function asegurarQueVendeLaLinea(int $lineaId): void
    {
        $this->asegurarSuscripcion();

        app(ActivarLineaDistribuidoraAction::class)->ejecutar($lineaId);
    }

    private function asegurarSuscripcion(): void
    {
        Suscripcion::withoutGlobalScopes()->updateOrCreate(
            [
                'distribuidora_id' => Distribuidora::where('slug', 'calzados-ramirez')->value('id'),
                'estado' => 'activa',
            ],
            [
                'plan_id' => PlanSuscripcion::firstOrFail()->id,
                'fecha_inicio' => now()->toDateString(),
                'precio_base_contratado' => 1000,
                'lineas_incluidas_contratadas' => 5,
                'precio_linea_extra_contratado' => 0,
                'lineas_extra_contratadas' => 0,
            ]
        );
    }

    // ------------------------------------------------------------------
    // Mayoreo con el descuento general
    // ------------------------------------------------------------------

    public function test_el_mayoreo_sale_del_descuento_general(): void
    {
        $this->conDescuentoDe(30);
        $producto = $this->productoDelCatalogo(precioPublico: 1000);

        $this->assertEqualsWithDelta(700.00, $this->precios()->mayoreo($producto), 0.001);
    }

    public function test_sin_descuento_el_mayoreo_es_igual_al_menudeo(): void
    {
        $this->conDescuentoDe(0);
        $producto = $this->productoDelCatalogo(precioPublico: 899.90);

        $this->assertEqualsWithDelta(899.90, $this->precios()->mayoreo($producto), 0.001);
    }

    public function test_el_menudeo_es_el_del_catalogo_y_no_lo_cambia_la_distribuidora(): void
    {
        $this->conDescuentoDe(40);
        $producto = $this->productoDelCatalogo(precioPublico: 1250.50);

        $this->ofertas()->ponerPrecioMayorista($producto->id, 600);

        $this->assertEqualsWithDelta(1250.50, $this->precios()->menudeo($producto), 0.001);
    }

    public function test_el_descuento_se_redondea_a_dos_decimales(): void
    {
        $this->conDescuentoDe(33.33);
        $producto = $this->productoDelCatalogo(precioPublico: 999.99);

        // 999.99 × 0.6667 = 666.69...
        $this->assertEqualsWithDelta(666.69, $this->precios()->mayoreo($producto), 0.011);
    }

    // ------------------------------------------------------------------
    // Precio propio por producto
    // ------------------------------------------------------------------

    public function test_el_precio_propio_le_gana_al_descuento_general(): void
    {
        $this->conDescuentoDe(30);
        $producto = $this->productoDelCatalogo(precioPublico: 1000);

        $this->ofertas()->ponerPrecioMayorista($producto->id, 540);

        $this->assertEqualsWithDelta(540.00, $this->precios()->mayoreo($producto), 0.001);
        $this->assertEqualsWithDelta(1000.00, $this->precios()->menudeo($producto), 0.001);
    }

    public function test_quitar_el_precio_propio_regresa_al_descuento_general(): void
    {
        $this->conDescuentoDe(25);
        $producto = $this->productoDelCatalogo(precioPublico: 800);
        $this->ofertas()->ponerPrecioMayorista($producto->id, 500);

        $this->ofertas()->quitarPrecioMayorista($producto->id);

        $this->assertEqualsWithDelta(600.00, $this->precios()->mayoreo($producto), 0.001);
        // Sin nada distinto que guardar, la fila se borra.
        $this->assertSame(0, OfertaDistribuidora::where('producto_campana_id', $producto->id)->count());
    }

    public function test_el_precio_propio_no_puede_ser_mayor_que_el_de_catalogo(): void
    {
        $producto = $this->productoDelCatalogo(precioPublico: 700);

        try {
            $this->ofertas()->ponerPrecioMayorista($producto->id, 900);
            $this->fail('Dejó poner un mayoreo más caro que el menudeo.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('no puede ser mayor que el precio de catálogo', $e->getMessage());
        }

        $this->assertSame(0, OfertaDistribuidora::where('producto_campana_id', $producto->id)->count());
    }

    public function test_el_precio_propio_tiene_que_ser_mayor_que_cero(): void
    {
        $producto = $this->productoDelCatalogo();

        $this->expectException(OperacionInvalidaException::class);
        $this->ofertas()->ponerPrecioMayorista($producto->id, 0);
    }

    public function test_el_descuento_general_tiene_que_estar_entre_0_y_100(): void
    {
        foreach ([-5, 100, 150] as $invalido) {
            try {
                $this->conDescuentoDe($invalido);
                $this->fail("Aceptó un descuento de {$invalido}%.");
            } catch (OperacionInvalidaException $e) {
                $this->assertStringContainsString('de 0 a 99.99', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Ocultar y volver a mostrar
    // ------------------------------------------------------------------

    public function test_ocultar_un_producto_y_volver_a_mostrarlo(): void
    {
        $producto = $this->productoDelCatalogo();

        $oculto = $this->ofertas()->ocultar($producto->id);
        $this->assertFalse($oculto->publicado);

        $this->ofertas()->mostrar($producto->id);

        // Vuelve a lo normal: sin fila, visible.
        $this->assertSame(0, OfertaDistribuidora::where('producto_campana_id', $producto->id)->count());
    }

    public function test_ocultar_no_borra_el_precio_propio(): void
    {
        $producto = $this->productoDelCatalogo(precioPublico: 1000);
        $this->ofertas()->ponerPrecioMayorista($producto->id, 650);

        $this->ofertas()->ocultar($producto->id);

        $oferta = OfertaDistribuidora::where('producto_campana_id', $producto->id)->firstOrFail();
        $this->assertFalse($oferta->publicado);
        $this->assertEqualsWithDelta(650.00, (float) $oferta->precio_mayorista, 0.001);
        $this->assertEqualsWithDelta(650.00, $this->precios()->mayoreo($producto), 0.001);
    }

    // ------------------------------------------------------------------
    // Solo lo suyo
    // ------------------------------------------------------------------

    public function test_no_se_puede_tocar_un_producto_de_una_linea_que_no_vende(): void
    {
        $this->asegurarSuscripcion();

        $otraLinea = Linea::create(['nombre' => 'Línea que no vende', 'activa' => true]);
        $campana = Campana::create([
            'linea_id' => $otraLinea->id,
            'nombre' => 'Temporada ajena',
            'estado' => 'activa',
        ]);

        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'MOD-AJENO',
            'nombre' => 'Producto de otra línea',
            'activo' => true,
        ]);

        $productoCampana = ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'AJENO-1',
            'precio_publico' => 500,
            'activo' => true,
        ]);

        try {
            $this->ofertas()->ponerPrecioMayorista($productoCampana->id, 400);
            $this->fail('Dejó tocar un producto de una línea que no vende.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('no tiene activa', $e->getMessage());
        }
    }

    public function test_no_se_puede_tocar_un_producto_que_no_existe(): void
    {
        $this->expectException(OperacionInvalidaException::class);
        $this->ofertas()->ocultar(999999);
    }

    public function test_cada_distribuidora_tiene_sus_propios_precios_del_mismo_producto(): void
    {
        $this->conDescuentoDe(20);
        $producto = $this->productoDelCatalogo(precioPublico: 1000);
        $this->ofertas()->ponerPrecioMayorista($producto->id, 700);

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Calzado Vecino',
            'slug' => 'calzado-vecino-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () use ($otra, $producto) {
            // La otra distribuidora no ve la oferta de la primera.
            $this->assertSame(0, OfertaDistribuidora::count());

            \App\Models\ConfiguracionDistribuidora::create([
                'distribuidora_id' => $otra->id,
                'descuento_mayorista_pct' => 10,
            ]);

            // Y su mayoreo sale de SU propio descuento.
            $this->assertEqualsWithDelta(900.00, (new PrecioEfectivo())->mayoreo($producto), 0.001);
        });

        // La primera sigue con su precio propio.
        Tenant::olvidarCache();
        $this->assertEqualsWithDelta(700.00, $this->precios()->mayoreo($producto), 0.001);
    }

    /** Para listas largas: se consultan todos los precios propios de una vez. */
    public function test_precargar_deja_listos_los_precios_de_varios_productos(): void
    {
        $this->conDescuentoDe(50);
        $conPrecioPropio = $this->productoDelCatalogo(precioPublico: 1000);
        $sinPrecioPropio = $this->productoDelCatalogo(precioPublico: 600);
        $this->ofertas()->ponerPrecioMayorista($conPrecioPropio->id, 450);

        $precios = $this->precios()->precargar([$conPrecioPropio, $sinPrecioPropio]);

        $this->assertEqualsWithDelta(450.00, $precios->mayoreo($conPrecioPropio), 0.001);
        $this->assertEqualsWithDelta(300.00, $precios->mayoreo($sinPrecioPropio), 0.001);
    }
}
