<?php

namespace Tests\Feature\Panel;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-234: buscador reutilizable y estilos base de controles de formulario.
 */
class BuscadorYControlesTest extends TestCase
{
    public function test_buscador_tiene_lupa_etiqueta_y_pasa_atributos_al_input(): void
    {
        $html = Blade::render('<x-ui.buscador wire:model.live="busqueda" placeholder="Buscar productos" class="w-80" />');

        $this->assertStringContainsString('data-buscador', $html);
        $this->assertStringContainsString('fp-buscador w-80', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('type="search"', $html);
        $this->assertStringContainsString('wire:model.live="busqueda"', $html);
        $this->assertStringContainsString('placeholder="Buscar productos"', $html);
        $this->assertStringContainsString('aria-label="Buscar productos"', $html);
        $this->assertStringContainsString('class="fp-buscador-input"', $html);
    }

    public function test_los_buscadores_del_sitio_usan_el_componente(): void
    {
        $vistas = [
            'tienda/⚡index', 'punto-venta/⚡index', 'auditoria/lista',
            'catalogo/⚡productos', 'catalogo/⚡precios', 'stock/⚡index',
        ];

        foreach ($vistas as $vista) {
            $contenido = file_get_contents(resource_path("views/components/{$vista}.blade.php"));
            $this->assertStringContainsString('<x-ui.buscador', $contenido, $vista);
            $this->assertStringNotContainsString('<input type="search"', $contenido, $vista);
        }
    }

    public function test_app_css_define_estilos_base_de_controles_y_error(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@layer components', $css);
        $this->assertStringContainsString('var(--color-fp-primary)', $css);
        $this->assertStringContainsString('var(--color-fp-danger)', $css);
        $this->assertStringContainsString("[aria-invalid='true']", $css);
        $this->assertStringContainsString('.fp-buscador', $css);
    }
}
