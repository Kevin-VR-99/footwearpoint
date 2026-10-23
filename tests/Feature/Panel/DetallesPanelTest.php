<?php

namespace Tests\Feature\Panel;

use App\Models\Campana;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Services\Catalogo\CatalogoVisible;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-265 (U5, E3-08) - Detalles de la interfaz del panel: saludo con nombre,
 * /pedidos/create sin error, enlace a variantes desde la lista de productos y
 * aviso de línea no asociada que dice cuál es.
 */
class DetallesPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private Distribuidora $distribuidora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function como(string $email): Usuario
    {
        $usuario = Usuario::where('email', $email)->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidora->id);

        return $usuario;
    }

    /** Un producto que la distribuidora vende hoy. */
    private function productoQueVende(): ProductoCampana
    {
        return app(CatalogoVisible::class)->consulta()->with('campana.linea')->orderBy('id')->firstOrFail();
    }

    /** Sus publicaciones en temporada activa (puede estar en varias). */
    private function publicacionesActivas(int $productoId)
    {
        return ProductoCampana::query()
            ->where('producto_id', $productoId)
            ->whereHas('campana', fn ($q) => $q->where('estado', 'activa'))
            ->with('campana.linea')
            ->get();
    }

    // 1. El saludo muestra el nombre del usuario.

    public function test_el_saludo_muestra_el_nombre_y_la_fecha_en_espanol(): void
    {
        $admin = $this->como(self::ADMIN);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hola, '.$admin->nombre.'.')
            ->assertDontSee('Hola, .')
            ->assertSee('Panel distribuidora')
            ->assertSee(now()->locale('es')->translatedFormat('D d M Y'));
    }

    public function test_el_empleado_ve_panel_del_personal(): void
    {
        $empleado = $this->como(self::EMPLEADO);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Panel del personal')
            ->assertDontSee('Panel distribuidora')
            ->assertSee('Hola, '.$empleado->nombre.'.');
    }

    // 2. /pedidos/create no da error.

    public function test_pedidos_create_lleva_a_nuevo_pedido_sin_error(): void
    {
        $this->como(self::EMPLEADO);

        $this->get('/pedidos/create')->assertRedirect(route('pedidos.create'));
        $this->get(route('pedidos.create'))->assertOk()->assertSee('Nuevo pedido');
        $this->get('/pedidos/abc')->assertNotFound();
    }

    // 3. La pantalla de variantes tiene enlace desde la lista de productos.

    public function test_la_lista_de_productos_enlaza_a_sus_variantes(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->productoQueVende();

        Livewire::test('catalogo.productos')
            ->assertSee('Ver variantes')
            ->assertSeeHtml('href="'.route('catalogo.producto.detalle', $pc->producto_id).'"');

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('Tallas del producto en el catálogo');
    }

    public function test_el_empleado_consulta_las_variantes_sin_acciones_de_administrador(): void
    {
        $this->como(self::EMPLEADO);
        $pc = $this->productoQueVende();

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('Tallas del producto en el catálogo')
            ->assertDontSee('← Catálogo');

        // El resto del catálogo sigue siendo del administrador.
        $this->get(route('distribuidora.catalogo'))->assertForbidden();
    }

    // 4. El aviso de línea no asociada dice cuál es.

    public function test_el_aviso_de_linea_no_asociada_dice_cual_es(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->productoQueVende();
        $activas = $this->publicacionesActivas($pc->producto_id);

        DistribuidoraLinea::query()
            ->whereIn('linea_id', $activas->pluck('campana.linea_id'))
            ->update(['activa' => false]);

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('«'.$pc->campana->linea->nombre)
            ->assertSee('que tu distribuidora no tiene asociada')
            ->assertSee('Actívala en Mis líneas')
            ->assertSeeHtml('href="'.route('catalogo.mis-lineas').'"');

        $this->como(self::EMPLEADO);

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('«'.$pc->campana->linea->nombre)
            ->assertSee('Pídele al administrador de tu distribuidora que la active.')
            ->assertDontSee('Actívala en Mis líneas');
    }

    public function test_el_aviso_dice_si_la_temporada_no_esta_activa(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->productoQueVende();

        Campana::query()
            ->whereIn('id', $this->publicacionesActivas($pc->producto_id)->pluck('campana_id'))
            ->update(['temporada_id' => \App\Models\Temporada::siguiente()->id]);

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('no está en una temporada activa')
            ->assertDontSee('no tiene asociada');
    }

    public function test_el_aviso_dice_si_lo_tiene_oculto(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->productoQueVende();

        foreach ($this->publicacionesActivas($pc->producto_id) as $publicacion) {
            OfertaDistribuidora::query()->updateOrCreate(
                ['distribuidora_id' => $this->distribuidora->id, 'producto_campana_id' => $publicacion->id],
                ['publicado' => false],
            );
        }

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertSee('lo tiene oculto');
    }

    public function test_un_producto_que_vende_no_muestra_aviso(): void
    {
        $this->como(self::ADMIN);
        $pc = $this->productoQueVende();

        $this->get(route('catalogo.producto.detalle', $pc->producto_id))
            ->assertOk()
            ->assertDontSee('Tu distribuidora no vende este producto');
    }

    private function borradorEnPantalla()
    {
        return Livewire::test('pedidos.create')
            ->set('propietario_id', (string) ClienteDirecto::query()->orderBy('id')->value('id'))
            ->call('crearBorrador')
            ->assertSet('errorMsg', '');
    }

    public function test_nuevo_pedido_sin_lineas_asociadas_lo_dice_y_enlaza_a_mis_lineas(): void
    {
        $this->como(self::ADMIN);
        DistribuidoraLinea::query()->update(['activa' => false]);

        $this->borradorEnPantalla()
            ->assertSee('Tu distribuidora no tiene líneas asociadas.')
            ->assertSee('Actívalas en Mis líneas');
    }

    public function test_nuevo_pedido_sin_temporada_activa_lo_dice(): void
    {
        $this->como(self::EMPLEADO);
        // Ninguna línea tiene publicación en la temporada en curso.
        $this->travelTo(now()->addYears(5));

        $this->borradorEnPantalla()
            ->assertSee('Tus líneas asociadas no tienen una temporada activa')
            ->assertDontSee('no tiene líneas asociadas');
    }
}
