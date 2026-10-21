<?php

namespace Tests\Feature\Diseno;

use Tests\TestCase;

/**
 * Mejoras de diseño web: clases reutilizables de movimiento y que las
 * pantallas del empleado usen los mismos componentes que el administrador.
 */
class MejorasDisenoWebTest extends TestCase
{
    private function vista(string $ruta): string
    {
        return file_get_contents(resource_path('views/'.$ruta));
    }

    public function test_la_hoja_de_estilos_define_las_clases_y_respeta_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['.fp-aparecer', '.fp-escalonado', '.fp-contenido', '.fp-tarjeta', '.fp-tarjeta-interactiva', '.fp-btn', '.fp-btn-primario', '.fp-tabla', '.fp-enlace', '.fp-nav-item', '.fp-chip'] as $clase) {
            $this->assertStringContainsString($clase, $css, "Falta la clase {$clase}");
        }

        // Hay varios bloques reduced-motion (sitio, admin, acceso): se revisan todos juntos.
        $reducido = implode("\n", array_slice(explode('@media (prefers-reduced-motion: reduce)', $css), 1));
        $this->assertStringContainsString('animation: none', $reducido);
        $this->assertStringContainsString('.fp-tarjeta-interactiva:hover', $reducido);
    }

    public function test_los_componentes_del_panel_usan_las_clases_reutilizables(): void
    {
        $this->assertStringContainsString("'fp-btn'", $this->vista('components/panel/boton.blade.php'));
        $this->assertStringContainsString('fp-tarjeta', $this->vista('components/panel/tarjeta.blade.php'));
        $this->assertStringContainsString('fp-tabla', $this->vista('components/panel/tabla.blade.php'));
        $this->assertStringContainsString('fp-aparecer', $this->vista('components/panel/encabezado.blade.php'));
        $this->assertStringContainsString('fp-nav-item', $this->vista('components/panel/enlace-lateral.blade.php'));
    }

    public function test_los_layouts_animan_el_contenido(): void
    {
        foreach (['panel', 'admin', 'public', 'tienda', 'guest'] as $layout) {
            $this->assertStringContainsString('fp-contenido', $this->vista("layouts/{$layout}.blade.php"), $layout);
        }

        // El layout heredado de distribuidora es el mismo panel del administrador.
        $this->assertStringContainsString("@include('layouts.panel')", $this->vista('layouts/distribuidora.blade.php'));
    }

    public function test_las_pantallas_del_empleado_no_usan_estilos_viejos(): void
    {
        $pantallas = [
            'dashboard/⚡index', 'punto-venta/⚡index', 'pedidos/⚡index', 'pedidos/⚡create', 'pedidos/⚡show',
            'stock/⚡index', 'ciclo/⚡index', 'vales/⚡index', 'cambios/⚡index', 'notificaciones/⚡index',
        ];

        foreach ($pantallas as $pantalla) {
            $html = $this->vista("components/{$pantalla}.blade.php");

            $this->assertStringNotContainsString('bg-white rounded-xl border border-slate-200', $html, $pantalla);
            $this->assertStringNotContainsString('bg-slate-50 text-slate-600', $html, $pantalla);
            $this->assertStringNotContainsString('bg-slate-50 text-left text-slate-500', $html, $pantalla);
            $this->assertStringNotContainsString('rounded-lg bg-fp-primary text-white px-4 py-2', $html, $pantalla);
        }
    }
}
