<?php

namespace Tests\Feature\Sprint5;

use App\Services\Auth\PermisoModuloService;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * S5-61 — Un ítem de menú «Tienda» con pestañas Stock y Venta.
 *
 * Stock y Venta local dejan de ser dos entradas. El contenido, las
 * subpestañas y los permisos finos siguen en sus pantallas. /stock y
 * /punto-venta siguen abriendo esas pantallas. /inventario/conteos abre
 * Stock → Conteo y /ventas abre Venta → Historial.
 */
class MenuTiendaTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_la_zona_horaria_de_la_aplicacion_es_mexico(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->assertSame('America/Mexico_City', date_default_timezone_get());
    }

    public function test_el_menu_tiene_un_solo_item_tienda_y_conserva_el_resto(): void
    {
        $this->comoPanel(self::ADMIN);

        foreach ([route('stock.index'), route('punto-venta.index'), route('pedidos.index')] as $ruta) {
            $html = $this->get($ruta)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-nav-tienda'), $ruta);
            $bloque = $this->bloqueOperacion($html);
            $this->assertStringContainsString('>Tienda</a>', $bloque);
            $this->assertStringNotContainsString('>Stock</a>', $bloque);
            $this->assertStringNotContainsString('Venta local', $bloque);
            $this->assertStringContainsString('>Compras</a>', $bloque);
            $this->assertSame(1, substr_count($bloque, 'data-nav-compras'));
            $this->assertStringNotContainsString('Ciclo de compra', $bloque);
            $this->assertStringNotContainsString(route('pedidos.index'), $bloque);
            $this->assertStringNotContainsString(route('vales.index'), $bloque);
            $this->assertStringContainsString(route('cambios.index'), $bloque);
            $this->assertStringContainsString('Caja y cortes', $bloque);
            $this->assertStringContainsString('Historial de clientes', $bloque);
            $this->assertStringContainsString('Comisiones', $bloque);
            $this->assertStringContainsString(route('stock.index').'"', $this->enlaceTienda($html));
        }

        $enStock = $this->enlaceTienda($this->get(route('stock.index'))->getContent());
        $enVenta = $this->enlaceTienda($this->get(route('punto-venta.index'))->getContent());
        $enPedidos = $this->enlaceTienda($this->get(route('pedidos.index'))->getContent());
        $this->assertStringContainsString('bg-white/15', $enStock);
        $this->assertStringContainsString('bg-white/15', $enVenta);
        $this->assertStringNotContainsString('bg-white/15', $enPedidos);
    }

    public function test_stock_muestra_la_pestana_tienda_y_sus_subpestanas(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = $this->get(route('stock.index'))->assertOk()
            ->assertSee('Stock local')
            ->assertSee('Existencias')
            ->assertSee('data-pestana-stock="existencias"', false)
            ->assertSee('data-pestana-stock="conteo"', false)
            ->getContent();

        $this->assertStringContainsString('aria-selected="true"', $this->pestanaTienda($html, 'stock'));
        $this->assertStringContainsString('aria-selected="false"', $this->pestanaTienda($html, 'venta'));
        $this->assertStringContainsString(route('punto-venta.index').'"', $this->pestanaTienda($html, 'venta'));
        $this->assertStringNotContainsString('pestana=', $this->pestanaTienda($html, 'venta'));

        $conteo = $this->get(route('stock.index', ['pestana' => 'conteo']))->assertOk()
            ->assertSee('Nuevo conteo')
            ->assertDontSee('Inventario físico')
            ->getContent();
        $this->assertStringContainsString(
            route('stock.index', ['pestana' => 'conteo']),
            $this->pestanaTienda($conteo, 'stock')
        );
        $this->assertStringNotContainsString('pestana=', $this->pestanaTienda($conteo, 'venta'));

        Livewire::test('stock.index')
            ->assertSee('data-pestana-tienda="stock"', false)
            ->assertSee('data-pestana-stock="existencias"', false)
            ->set('pestana', 'conteo')
            ->assertSet('pestana', 'conteo')
            ->assertSee('data-pestana-stock="conteo"', false);
    }

    public function test_venta_muestra_la_pestana_tienda_el_cobro_y_el_historial(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = $this->get(route('punto-venta.index'))->assertOk()
            ->assertSee('Venta local')
            ->assertSee('Venta en curso')
            ->assertSee('data-pestana-venta="cobrar"', false)
            ->assertSee('data-pestana-venta="historial"', false)
            ->assertDontSee('Ventas de mostrador')
            ->getContent();

        $this->assertStringContainsString('aria-selected="true"', $this->pestanaTienda($html, 'venta'));
        $this->assertStringContainsString('aria-selected="false"', $this->pestanaTienda($html, 'stock'));
        $this->assertStringContainsString(route('stock.index').'"', $this->pestanaTienda($html, 'stock'));
        $this->assertStringNotContainsString('pestana=', $this->pestanaTienda($html, 'stock'));

        $historial = $this->get(route('punto-venta.index', ['pestana' => 'historial']))->assertOk()
            ->assertDontSee('Ventas de mostrador')
            ->assertDontSee('Nueva venta')
            ->assertDontSee('Inventario físico')
            ->getContent();
        $this->assertStringContainsString(
            route('punto-venta.index', ['pestana' => 'historial']),
            $this->pestanaTienda($historial, 'venta')
        );
        $this->assertStringNotContainsString('pestana=', $this->pestanaTienda($historial, 'stock'));

        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon, 'El seeder no dejó existencias para cobrar.');

        Livewire::test('punto-venta.index')
            ->assertSee('data-pestana-tienda="venta"', false)
            ->call('agregar', $renglon['clave'])
            ->assertDontSee('Vale (opcional)')
            ->assertDontSee('Folio del vale')
            ->assertDontSee('wire:click="aplicarVale"', false)
            ->set('pestana', 'historial')
            ->assertSet('pestana', 'historial')
            ->assertSee('data-pestana-venta="historial"', false)
            ->assertDontSee('Vale (opcional)');
    }

    public function test_las_direcciones_viejas_siguen_abriendo_la_subpestana(): void
    {
        $this->comoPanel(self::ADMIN);

        $this->get('/stock')->assertOk()->assertSee('data-pestana-tienda="stock"', false);
        $this->get('/punto-venta')->assertOk()->assertSee('data-pestana-tienda="venta"', false);
        $this->get(route('distribuidora.stock'))->assertRedirect(route('stock.index'));

        $conteo = $this->get('/inventario/conteos?origen=lista');
        $conteo->assertRedirect();
        $destinoConteo = (string) $conteo->headers->get('Location');
        $this->assertStringContainsString('/stock', $destinoConteo);
        $this->assertStringContainsString('pestana=conteo', $destinoConteo);
        $this->assertStringContainsString('origen=lista', $destinoConteo);

        $ventas = $this->get('/ventas?busqueda=abc');
        $ventas->assertRedirect();
        $destinoVentas = (string) $ventas->headers->get('Location');
        $this->assertStringContainsString('/punto-venta', $destinoVentas);
        $this->assertStringContainsString('pestana=historial', $destinoVentas);
        $this->assertStringContainsString('busqueda=abc', $destinoVentas);
    }

    public function test_el_empleado_ve_tienda_sin_permisos_de_mas(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $html = $this->get(route('stock.index'))->assertOk()->getContent();
        $bloque = $this->bloqueOperacion($html);
        $this->assertStringContainsString('>Tienda</a>', $bloque);
        $this->assertStringNotContainsString('Comisiones', $bloque);
        $this->assertStringContainsString('Caja y cortes', $bloque);
        $this->assertStringContainsString('aria-selected="true"', $this->pestanaTienda($html, 'stock'));
        $this->assertStringContainsString('aria-selected="false"', $this->pestanaTienda($html, 'venta'));

        $this->get(route('punto-venta.index'))->assertOk()
            ->assertSee('data-pestana-tienda="venta"', false)
            ->assertSee('Venta en curso');

        $this->staffDe(self::EMPLEADO)
            ->forceFill(['permisos_finos' => [
                PermisoModuloService::INVENTARIO_CONTEO => false,
                PermisoModuloService::STOCK_AJUSTAR => false,
            ]])->save();
        $this->comoPanel(self::EMPLEADO);

        $this->get(route('conteos.index'))->assertForbidden();
        $this->get(route('stock.index', ['pestana' => 'conteo']))->assertOk()
            ->assertSee('data-pestana-tienda="stock"', false)
            ->assertSee('data-pestana-tienda="venta"', false)
            ->assertDontSee('data-pestana-stock="conteo"', false)
            ->assertDontSee('Nuevo conteo')
            ->assertSee('Existencias')
            ->assertSee('pídele el permiso');
        $venta = $this->get(route('punto-venta.index'))->assertOk()
            ->assertSee('data-pestana-tienda="venta"', false)
            ->assertSee('Venta en curso');
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon);
        Livewire::test('punto-venta.index')->call('agregar', $renglon['clave'])
            ->assertDontSee('Vale (opcional)')
            ->assertDontSee('wire:click="aplicarVale"', false);
        $venta->assertSee('data-pestana-tienda="stock"', false);

        Livewire::test('stock.index')->set('pestana', 'conteo')->assertSet('pestana', 'existencias');
    }

    public function test_quien_no_es_personal_no_entra_a_tienda_ni_a_las_rutas_viejas(): void
    {
        foreach ([self::MARIA, self::JOSE] as $correo) {
            foreach (['/stock', '/punto-venta', '/ventas', '/inventario/conteos', '/distribuidora/stock'] as $ruta) {
                $this->comoPanel($correo);
                $this->get($ruta)->assertRedirect(route('login'));
                $this->assertGuest();
            }
        }

        $this->actingAs($this->usuario(AdminGeneralSeeder::EMAIL));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-nav-tienda', false);
        // Sin distribuidora no hay sucursal que operar: Tienda no le abre el panel.
        $this->get(route('stock.index'))->assertForbidden();
        $this->get(route('punto-venta.index'))->assertForbidden();
    }

    private function bloqueOperacion(string $html): string
    {
        $inicio = strpos($html, 'data-nav-operacion');
        $this->assertNotFalse($inicio, 'El menú de operación no está en la página.');
        $finSeccion = strpos($html, 'uppercase tracking-[0.16em]', $inicio);
        $finNav = strpos($html, '</nav>', $inicio);
        $this->assertNotFalse($finNav);
        $fin = $finSeccion === false ? $finNav : min($finSeccion, $finNav);

        return substr($html, $inicio, $fin - $inicio);
    }

    private function enlaceTienda(string $html): string
    {
        $marca = 'data-nav-tienda';
        $pos = strpos($html, $marca);
        $this->assertNotFalse($pos);
        $inicio = strrpos(substr($html, 0, $pos), '<a ');
        $fin = strpos($html, '</a>', $pos);
        $this->assertNotFalse($inicio);
        $this->assertNotFalse($fin);

        return substr($html, $inicio, $fin - $inicio);
    }

    private function pestanaTienda(string $html, string $clave): string
    {
        $marca = 'data-pestana-tienda="'.$clave.'"';
        $pos = strpos($html, $marca);
        $this->assertNotFalse($pos, $marca);
        $inicio = strrpos(substr($html, 0, $pos), '<a ');
        $fin = strpos($html, '</a>', $pos);
        $this->assertNotFalse($inicio);
        $this->assertNotFalse($fin);

        return substr($html, $inicio, $fin - $inicio);
    }
}
