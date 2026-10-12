<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\OfertaDistribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-249 (R7, E4-14) — Pantalla "Precios y publicación" de la distribuidora:
 * descuento general de mayoreo, precio propio por producto y ocultar/mostrar.
 * También confirma que las pantallas de la distribuidora ya no tienen botones
 * para crear líneas, marcas ni productos (se quitaron en TG-213), y que el
 * catálogo maestro del admin general sí los conserva.
 */
class PreciosYPublicacionPantallaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoAdminDistribuidora();
    }

    private function comoAdminDistribuidora(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    private function producto(float $precioPublico = 1000, string $nombre = 'Producto pantalla precios'): ProductoCampana
    {
        $campana = Campana::where('estado', 'activa')->firstOrFail();

        Suscripcion::withoutGlobalScopes()->updateOrCreate(
            ['distribuidora_id' => Distribuidora::where('slug', 'calzados-ramirez')->value('id'), 'estado' => 'activa'],
            [
                'plan_id' => PlanSuscripcion::firstOrFail()->id,
                'fecha_inicio' => now()->toDateString(),
                'precio_base_contratado' => 1000,
                'lineas_incluidas_contratadas' => 5,
                'precio_linea_extra_contratado' => 0,
                'lineas_extra_contratadas' => 0,
            ]
        );
        app(ActivarLineaDistribuidoraAction::class)->ejecutar((int) $campana->linea_id);

        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'MOD-PP-'.uniqid(),
            'nombre' => $nombre,
            'activo' => true,
        ]);

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'PP-'.uniqid(),
            'precio_publico' => $precioPublico,
            'activo' => true,
        ]);
    }

    private function pantalla()
    {
        return Livewire::test('catalogo.precios');
    }

    public function test_el_admin_de_la_distribuidora_entra_a_la_pantalla(): void
    {
        $this->get(route('catalogo.precios'))->assertOk()->assertSee('Precios y publicación');
    }

    public function test_el_empleado_no_puede_entrar(): void
    {
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $this->get(route('catalogo.precios'))->assertForbidden();
    }

    public function test_fijar_el_descuento_cambia_el_mayoreo_que_se_muestra(): void
    {
        $pc = $this->producto(1000, 'Bota descuento');

        $this->pantalla()
            ->set('descuento', '25')
            ->call('guardarDescuento')
            ->assertSet('errorMsg', '')
            ->set('busqueda', 'Bota descuento')
            ->assertSee('$750.00');

        $this->assertEqualsWithDelta(25, (float) ConfiguracionDistribuidora::query()->value('descuento_mayorista_pct'), 0.001);
    }

    public function test_un_descuento_fuera_de_rango_muestra_un_mensaje_legible(): void
    {
        $this->pantalla()
            ->set('descuento', '150')
            ->call('guardarDescuento')
            ->assertSet('errorMsg', 'El descuento de mayoreo tiene que ser un porcentaje de 0 a 99.99.');

        $this->pantalla()
            ->set('descuento', 'abc')
            ->call('guardarDescuento')
            ->assertSet('errorMsg', 'Escribe el descuento como un número, por ejemplo 15.');
    }

    public function test_poner_y_quitar_precio_propio(): void
    {
        $pc = $this->producto(1000);

        $this->pantalla()
            ->set("preciosPropios.{$pc->id}", '640')
            ->call('guardarPrecio', $pc->id)
            ->assertSet('errorMsg', '');

        $this->assertEqualsWithDelta(640, (float) OfertaDistribuidora::where('producto_campana_id', $pc->id)->value('precio_mayorista'), 0.001);

        $this->pantalla()->call('quitarPrecio', $pc->id)->assertSet('errorMsg', '');

        $this->assertSame(0, OfertaDistribuidora::where('producto_campana_id', $pc->id)->count());
    }

    public function test_rechaza_precio_propio_mayor_al_menudeo_o_no_positivo(): void
    {
        $pc = $this->producto(1000);

        $this->pantalla()
            ->set("preciosPropios.{$pc->id}", '1200')
            ->call('guardarPrecio', $pc->id)
            ->assertSet('errorMsg', 'El precio de mayoreo no puede ser mayor que el precio de catálogo ($1,000.00).');

        $this->pantalla()
            ->set("preciosPropios.{$pc->id}", '0')
            ->call('guardarPrecio', $pc->id)
            ->assertSet('errorMsg', 'El precio de mayoreo tiene que ser mayor que cero.');

        $this->assertSame(0, OfertaDistribuidora::where('producto_campana_id', $pc->id)->count());
    }

    public function test_ocultar_lo_quita_del_catalogo_pero_sigue_en_la_pantalla_y_se_puede_mostrar(): void
    {
        $pc = $this->producto(1000, 'Tenis para ocultar');

        $this->pantalla()->call('ocultar', $pc->id)->assertSet('errorMsg', '');

        $this->assertFalse(app(CatalogoVisible::class)->puedeVender($pc->id));
        Livewire::test('catalogo.productos')->set('busqueda', 'Tenis para ocultar')->assertDontSee('Tenis para ocultar');

        $this->pantalla()
            ->set('filtro', 'ocultos')
            ->assertSee('Tenis para ocultar')
            ->assertSee('Mostrar')
            ->call('mostrar', $pc->id)
            ->assertSet('errorMsg', '');

        $this->assertTrue(app(CatalogoVisible::class)->puedeVender($pc->id));
    }

    public function test_no_se_puede_tocar_un_producto_de_una_linea_que_no_vende(): void
    {
        $linea = \App\Models\Linea::create(['nombre' => 'Línea ajena PP', 'activa' => true]);
        $campana = Campana::create(['linea_id' => $linea->id, 'nombre' => 'Temp ajena', 'estado' => 'activa']);
        $pc = ProductoCampana::create([
            'producto_id' => Producto::firstOrFail()->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'AJ-'.uniqid(),
            'precio_publico' => 500,
            'activo' => true,
        ]);

        $this->pantalla()
            ->call('ocultar', $pc->id)
            ->assertSet('errorMsg', 'Ese producto es de una línea que tu distribuidora no tiene activa.');
    }

    public function test_otra_distribuidora_no_ve_lo_que_oculto_esta(): void
    {
        $pc = $this->producto(1000);
        app(GestionarOfertaDistribuidoraAction::class)->ocultar($pc->id);

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Vecina PP',
            'slug' => 'vecina-pp-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () {
            $this->assertSame(0, OfertaDistribuidora::count());
        });
    }

    public function test_las_pantallas_de_la_distribuidora_no_tienen_botones_de_alta(): void
    {
        foreach (['catalogo.lineas', 'catalogo.marcas', 'catalogo.productos', 'catalogo.precios'] as $componente) {
            Livewire::test($componente)
                ->assertDontSee('Crear línea')
                ->assertDontSee('Crear marca')
                ->assertDontSee('Crear producto')
                ->assertDontSee('Nueva línea')
                ->assertDontSee('Nueva marca')
                ->assertDontSee('Nuevo producto');
        }
    }

    public function test_el_catalogo_maestro_del_admin_general_conserva_sus_botones(): void
    {
        $this->actingAs(Usuario::where('email', 'admin.general@footwearpoint.test')->firstOrFail());
        Tenant::olvidarCache();

        $this->get(route('admin.catalogo'))
            ->assertOk()
            ->assertSee('Crear línea');

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->assertSee('Crear marca');
    }
}
