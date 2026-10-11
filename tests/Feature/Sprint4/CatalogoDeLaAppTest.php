<?php

namespace Tests\Feature\Sprint4;

use App\Models\Distribuidora;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Distribuidora\FijarDescuentoMayoristaAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-213 (Ola 2, K6) — El catálogo que recibe la app.
 *
 * Por dentro cambió todo (catálogo compartido, precios por distribuidora),
 * pero la app NO se puede romper: GET /api/catalogo tiene que responder con
 * los mismos campos de siempre.
 *
 * Además, cada quien paga lo suyo: el cliente directo ve el precio de menudeo
 * del catálogo y el revendedor, el mayoreo de su distribuidora.
 */
class CatalogoDeLaAppTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function conDescuentoDe(float $porcentaje): void
    {
        $this->como('admin@calzadosramirez.test');
        app(FijarDescuentoMayoristaAction::class)->ejecutar($porcentaje);
    }

    private function catalogo(): array
    {
        return $this->getJson('/api/catalogo')->assertOk()->json('data');
    }

    private function precios(): PrecioEfectivo
    {
        return new PrecioEfectivo();
    }

    // ------------------------------------------------------------------
    // Los mismos campos de siempre
    // ------------------------------------------------------------------

    public function test_la_app_recibe_los_mismos_campos_de_siempre(): void
    {
        $this->como(self::MARIA);

        $this->getJson('/api/catalogo')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'producto' => ['id', 'modelo', 'nombre', 'marca', 'linea', 'categoria'],
                        'codigo_catalogo',
                        'precio_minorista_sugerido',
                        'precio_mayorista',
                        'imagenes',
                        'variantes' => [
                            '*' => ['variante_id', 'sku', 'talla', 'color', 'nombre_color_comercial', 'disponibilidad'],
                        ],
                    ],
                ],
            ]);
    }

    /** La línea ya no es del producto: sale de su temporada, pero la app la sigue viendo. */
    public function test_la_app_sigue_viendo_la_linea_del_producto(): void
    {
        $this->como(self::MARIA);

        $primero = $this->catalogo()[0];

        $this->assertNotNull($primero['producto']['linea']);
        $this->assertNotEmpty($primero['producto']['linea']['nombre']);
    }

    // ------------------------------------------------------------------
    // Cada quien con su precio
    // ------------------------------------------------------------------

    public function test_el_cliente_directo_ve_el_precio_de_catalogo_y_no_el_mayoreo(): void
    {
        $this->conDescuentoDe(30);
        $this->como(self::JOSE);

        $primero = $this->catalogo()[0];
        $productoCampana = ProductoCampana::findOrFail($primero['id']);

        $this->assertEqualsWithDelta(
            (float) $productoCampana->precio_publico,
            $primero['precio_minorista_sugerido'],
            0.001
        );

        // El precio de costo del revendedor no se le manda.
        $this->assertArrayNotHasKey('precio_mayorista', $primero);
    }

    public function test_la_revendedora_ve_el_mayoreo_de_su_distribuidora(): void
    {
        $this->conDescuentoDe(30);
        $this->como(self::MARIA);

        // El primero que NO tenga precio propio: el descuento general solo se
        // nota en esos, y el catalogo demo ya trae uno con su propio precio
        // (TG-217).
        $primero = collect($this->catalogo())->firstWhere(
            fn (array $producto) => OfertaDistribuidora::where('producto_campana_id', $producto['id'])
                ->whereNotNull('precio_mayorista')
                ->doesntExist()
        );

        $this->assertNotNull($primero, 'Todo el catalogo tiene precio propio');

        $productoCampana = ProductoCampana::findOrFail($primero['id']);

        $this->assertEqualsWithDelta(
            (float) $productoCampana->precio_publico,
            $primero['precio_minorista_sugerido'],
            0.001
        );
        $this->assertEqualsWithDelta(
            round((float) $productoCampana->precio_publico * 0.70, 2),
            $primero['precio_mayorista'],
            0.011
        );
    }

    public function test_el_precio_propio_del_producto_le_gana_al_descuento_general(): void
    {
        $this->conDescuentoDe(10);

        $productoCampana = ProductoCampana::firstOrFail();
        app(GestionarOfertaDistribuidoraAction::class)->ponerPrecioMayorista($productoCampana->id, 123.45);

        $this->como(self::MARIA);

        $delCatalogo = collect($this->catalogo())->firstWhere('id', $productoCampana->id);

        $this->assertEqualsWithDelta(123.45, $delCatalogo['precio_mayorista'], 0.001);
    }

    // ------------------------------------------------------------------
    // Solo lo que esa distribuidora vende
    // ------------------------------------------------------------------

    public function test_la_app_no_muestra_lo_que_la_distribuidora_oculto(): void
    {
        $productoCampana = ProductoCampana::firstOrFail();

        $this->como('admin@calzadosramirez.test');
        app(GestionarOfertaDistribuidoraAction::class)->ocultar($productoCampana->id);

        $this->como(self::MARIA);

        $this->assertNotContains($productoCampana->id, collect($this->catalogo())->pluck('id'));
    }

    public function test_si_la_distribuidora_deja_de_vender_la_linea_su_catalogo_queda_vacio(): void
    {
        $this->como('admin@calzadosramirez.test');

        \App\Models\DistribuidoraLinea::withoutGlobalScopes()
            ->where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))
            ->update(['activa' => false]);

        $this->como(self::MARIA);

        $this->assertSame([], $this->catalogo());
    }
}
