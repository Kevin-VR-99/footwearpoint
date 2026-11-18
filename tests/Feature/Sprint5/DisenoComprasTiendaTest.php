<?php

namespace Tests\Feature\Sprint5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/**
 * S5-76 / Taiga 358 — La pestaña activa de Compras y de Tienda se distingue
 * con el sistema de diseño. Las rutas y el menú no cambian.
 */
class DisenoComprasTiendaTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_compras_marca_solo_la_pestana_activa_y_conserva_las_rutas(): void
    {
        $this->comoPanel(self::ADMIN);

        $html = $this->get(route('pedidos.index'))->assertOk()->getContent();
        $pedidos = $this->pestana($html, 'data-pestana-compras="pedidos"');
        $ciclo = $this->pestana($html, 'data-pestana-compras="ciclo"');

        $this->assertStringContainsString('fp-pestana-activa', $pedidos);
        $this->assertStringContainsString('aria-selected="true"', $pedidos);
        $this->assertStringContainsString('aria-current="page"', $pedidos);
        $this->assertStringContainsString(route('pedidos.index'), $pedidos);
        $this->assertStringNotContainsString('fp-pestana-activa', $ciclo);
        $this->assertStringContainsString('aria-selected="false"', $ciclo);
        $this->assertStringContainsString(route('ciclo.index'), $ciclo);
        $this->assertStringNotContainsString('text-slate-600', $pedidos.$ciclo);
        $this->assertStringContainsString('fp-pestanas-seccion', $html);
        $this->assertStringContainsString('<x-ui.saltar-al-contenido />', file_get_contents(resource_path('views/layouts/panel.blade.php')));

        $this->get(route('distribuidora.pedidos'))->assertRedirect(route('pedidos.index'));
    }

    public function test_tienda_marca_la_seccion_y_la_subpestana_sin_mover_rutas(): void
    {
        $this->comoPanel(self::ADMIN);

        $stock = $this->get(route('stock.index'))->assertOk()->getContent();
        $pestanaStock = $this->pestana($stock, 'data-pestana-tienda="stock"');
        $pestanaVenta = $this->pestana($stock, 'data-pestana-tienda="venta"');

        $this->assertStringContainsString('fp-pestana-activa', $pestanaStock);
        $this->assertStringContainsString('aria-current="page"', $pestanaStock);
        $this->assertStringNotContainsString('fp-pestana-activa', $pestanaVenta);
        $this->assertStringContainsString(route('punto-venta.index').'"', $pestanaVenta);
        $this->assertStringContainsString('fp-pestana-activa', $this->pestana($stock, 'data-pestana-stock="existencias"'));
        $this->assertStringNotContainsString('fp-pestana-activa', $this->pestana($stock, 'data-pestana-stock="conteo"'));

        $venta = $this->get(route('punto-venta.index'))->assertOk()->getContent();
        $this->assertStringContainsString('fp-pestana-activa', $this->pestana($venta, 'data-pestana-tienda="venta"'));
        $this->assertStringNotContainsString('fp-pestana-activa', $this->pestana($venta, 'data-pestana-tienda="stock"'));
        $this->assertStringContainsString('fp-pestana-activa', $this->pestana($venta, 'data-pestana-venta="cobrar"'));
        $this->assertStringNotContainsString('fp-pestana-activa', $this->pestana($venta, 'data-pestana-venta="historial"'));

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('--radius-fp-control', $css);
        $this->assertStringContainsString('--color-fp-text-muted', $css);
        $this->assertStringContainsString('--color-fp-primary: #2563EB;', $css);
        $this->assertStringContainsString('--color-fp-sidebar: #111E38;', $css);
        $this->assertStringContainsString('--color-fp-accent: #1E2F52;', $css);
    }

    private function pestana(string $html, string $marca): string
    {
        $pos = strpos($html, $marca);
        $this->assertNotFalse($pos, $marca);
        $inicio = strrpos(substr($html, 0, $pos), '<');
        $fin = strpos($html, '>', $pos);

        return substr($html, $inicio, $fin - $inicio + 1);
    }
}
