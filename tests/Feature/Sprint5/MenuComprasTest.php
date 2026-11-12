<?php

namespace Tests\Feature\Sprint5;

use App\Services\Auth\PermisoModuloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * S5-59 — Un ítem Compras con pestañas Ciclo, Pedidos, Vales y Anticipos.
 * Anticipos no trae configuración ni listado. /ciclo, /pedidos y /vales siguen
 * siendo esas pantallas.
 */
class MenuComprasTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_menu_tiene_un_solo_item_compras(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->comoPanel(self::ADMIN);

        foreach ([route('ciclo.index'), route('pedidos.index'), route('vales.index'), route('compras.anticipos')] as $ruta) {
            $html = $this->get($ruta)->assertOk()->getContent();
            $bloque = $this->bloque($html);
            $this->assertSame(1, substr_count($bloque, 'data-nav-compras'), $ruta);
            $this->assertStringContainsString('>Compras</a>', $bloque);
            $this->assertStringNotContainsString('Ciclo de compra', $bloque);
            $this->assertStringContainsString('bg-white/15', $this->enlace($html, 'data-nav-compras'));
            $this->assertStringNotContainsString('bg-white/15', $this->enlace($html, 'data-nav-tienda'));
            $this->assertStringContainsString('data-pestana-compras="ciclo"', $html);
            $this->assertStringContainsString('data-pestana-compras="pedidos"', $html);
            $this->assertStringContainsString('data-pestana-compras="vales"', $html);
            $this->assertStringContainsString('data-pestana-compras="anticipos"', $html);
            $this->assertStringContainsString(route('cambios.index'), $bloque);
        }

        $this->get('/ciclo')->assertOk()->assertSee('Ciclo de compra')->assertSee('data-pestana-compras="ciclo"', false);
        $this->get('/pedidos')->assertOk()->assertSee('Listado de pedidos')->assertSee('data-pestana-compras="pedidos"', false);
        $this->get('/vales')->assertOk()->assertSee('Emisión manual')->assertSee('data-pestana-compras="vales"', false);
        $this->get(route('distribuidora.ciclos'))->assertRedirect(route('ciclo.index'));
        $this->get(route('distribuidora.pedidos'))->assertRedirect(route('pedidos.index'));
        $this->get(route('distribuidora.vales'))->assertRedirect(route('vales.index'));
    }

    public function test_anticipos_no_muestra_configuracion_ni_vales(): void
    {
        $this->comoPanel(self::ADMIN);
        $this->get(route('compras.anticipos'))->assertOk()
            ->assertSee('data-anticipos-config', false)
            ->assertSee('data-anticipos-pendientes', false)
            ->assertSee('Anticipo por producto (MXN)')
            ->assertDontSee('Emisión manual')
            ->assertDontSee('Días de vigencia del vale');

        $this->comoPanel(self::EMPLEADO);
        $html = $this->get(route('ciclo.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>Compras</a>', $this->bloque($html));
        $this->assertStringNotContainsString('Comisiones', $this->bloque($html));
        $this->get(route('compras.anticipos'))->assertOk()
            ->assertSee('data-anticipos-pendientes', false)
            ->assertDontSee('data-anticipos-config', false);

        $this->staffDe(self::EMPLEADO)
            ->forceFill(['permisos_finos' => [PermisoModuloService::INVENTARIO_CONTEO => false]])
            ->save();
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('conteos.index'))->assertForbidden();
        $this->get(route('ciclo.index'))->assertOk();
        $this->get(route('pedidos.index'))->assertOk();
        $this->get(route('vales.index'))->assertOk();
    }

    public function test_quien_no_es_personal_no_entra(): void
    {
        foreach (['/ciclo', '/pedidos', '/vales', '/compras/anticipos'] as $ruta) {
            $this->comoPanel(self::MARIA);
            $this->get($ruta)->assertRedirect(route('login'));
        }
    }

    private function bloque(string $html): string
    {
        $inicio = strpos($html, 'data-nav-operacion');
        $this->assertNotFalse($inicio);
        $finSeccion = strpos($html, 'uppercase tracking-[0.16em]', $inicio);
        $finNav = strpos($html, '</nav>', $inicio);
        $fin = $finSeccion === false ? $finNav : min($finSeccion, $finNav);

        return substr($html, $inicio, $fin - $inicio);
    }

    private function enlace(string $html, string $marca): string
    {
        $pos = strpos($html, $marca);
        $this->assertNotFalse($pos);
        $inicio = strrpos(substr($html, 0, $pos), '<a ');
        $fin = strpos($html, '</a>', $pos);

        return substr($html, $inicio, $fin - $inicio);
    }
}
