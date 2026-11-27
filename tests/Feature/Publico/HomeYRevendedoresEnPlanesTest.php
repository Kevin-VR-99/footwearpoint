<?php

namespace Tests\Feature\Publico;

use App\Models\PlanSuscripcion;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enlace «Home» en el encabezado público (a la izquierda de Marketplace,
 * también en el menú del celular) y el límite de revendedores en los planes
 * del inicio.
 */
class HomeYRevendedoresEnPlanesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function bloque(string $html, string $marca, string $cierre): string
    {
        $inicio = strpos($html, $marca);
        $this->assertNotFalse($inicio, "No está {$marca}");

        return substr($html, $inicio, strpos($html, $cierre, $inicio) - $inicio);
    }

    public function test_home_va_antes_de_marketplace_y_lleva_al_inicio(): void
    {
        foreach ([route('marketplace'), route('inicio')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $nav = $this->bloque($html, 'data-nav-publica>', '</nav>');

            $this->assertStringContainsString('href="'.route('inicio').'"', $nav);
            $this->assertLessThan(strpos($nav, 'Marketplace'), strpos($nav, 'Home'));
        }
    }

    public function test_el_menu_del_celular_tambien_trae_home(): void
    {
        $html = $this->get(route('marketplace'))->assertOk()->getContent();
        $menu = $this->bloque($html, 'data-nav-publica-movil', '</details>');

        $this->assertStringContainsString('Home', $menu);
        $this->assertStringContainsString('href="'.route('inicio').'"', $menu);
        $this->assertLessThan(strpos($menu, 'Marketplace'), strpos($menu, 'Home'));
        $this->assertStringContainsString('Planes', $menu);
    }

    public function test_en_el_inicio_home_queda_marcado_como_pagina_actual(): void
    {
        $nav = $this->bloque($this->get(route('inicio'))->getContent(), 'data-nav-publica>', '</nav>');

        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('inicio'), '#').'"\s+aria-current="page"#', $nav);
    }

    public function test_la_comparativa_trae_revendedores_incluidos_y_su_costo_extra(): void
    {
        $html = $this->get(route('inicio'))->assertOk()->getContent();
        $tabla = $this->bloque($html, 'data-comparativa-planes', '</table>');

        $this->assertStringContainsString('Revendedores incluidos', $tabla);
        $this->assertStringContainsString('Cada revendedor extra, al mes', $tabla);

        $fila = $this->bloque($tabla, 'data-fila-comparativa="revendedores-incluidos"', '</tr>');
        foreach (['25', '50', '100'] as $limite) {
            $this->assertStringContainsString(">{$limite}</td>", $fila);
        }

        $fila = $this->bloque($tabla, 'data-fila-comparativa="revendedor-extra"', '</tr>');
        $this->assertStringContainsString('$10.00', $fila);
    }

    public function test_la_tabla_sale_de_la_base_si_el_admin_cambia_el_limite(): void
    {
        PlanSuscripcion::where('nombre', 'Pro')->update(['revendedores_incluidos' => 70, 'precio_revendedor_extra' => 15]);

        $tabla = $this->bloque($this->get(route('inicio'))->getContent(), 'data-comparativa-planes', '</table>');

        $this->assertStringContainsString('>70</td>', $this->bloque($tabla, 'data-fila-comparativa="revendedores-incluidos"', '</tr>'));
        $this->assertStringContainsString('$15.00', $this->bloque($tabla, 'data-fila-comparativa="revendedor-extra"', '</tr>'));
    }

    public function test_las_tarjetas_muestran_el_limite_de_revendedores(): void
    {
        $this->get(route('inicio'))
            ->assertOk()
            ->assertSee('data-plan-revendedores', false)
            ->assertSee('Hasta 25 revendedores incluidos')
            ->assertSee('Hasta 100 revendedores incluidos');
    }
}
