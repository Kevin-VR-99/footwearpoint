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
        $this->assertMatchesRegularExpression('/<label class="[^"]*\bfp-buscador\b[^"]*\bw-80\b|<label class="[^"]*\bw-80\b[^"]*\bfp-buscador\b/', $html);
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
            'catalogo/⚡productos', 'catalogo/⚡precios', 'stock/⚡index', 'cambios/⚡index',
        ];

        foreach ($vistas as $vista) {
            $contenido = file_get_contents(resource_path("views/components/{$vista}.blade.php"));
            $this->assertStringContainsString('<x-ui.buscador', $contenido, $vista);
            $this->assertStringNotContainsString('<input type="search"', $contenido, $vista);
        }
    }

    public function test_ningun_buscador_del_sitio_queda_sin_el_componente(): void
    {
        $sinComponente = [];
        $vistas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($vistas as $archivo) {
            if (! str_ends_with($archivo->getFilename(), '.blade.php')
                || str_ends_with($archivo->getPathname(), 'ui'.DIRECTORY_SEPARATOR.'buscador.blade.php')) {
                continue;
            }
            preg_match_all('/<input\b[^>]*>/is', file_get_contents($archivo->getPathname()), $inputs);
            foreach ($inputs[0] as $input) {
                if (preg_match('/type="search"|placeholder="\s*Buscar/i', $input)) {
                    $sinComponente[] = str_replace(resource_path('views'), '', $archivo->getPathname());
                }
            }
        }

        $this->assertSame([], $sinComponente, 'Buscadores sin <x-ui.buscador>: '.implode(', ', $sinComponente));
    }

    public function test_app_css_define_estilos_base_de_controles_y_error(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@layer components', $css);
        $this->assertStringContainsString('var(--color-fp-primary)', $css);
        $this->assertStringContainsString('var(--color-fp-danger)', $css);
        $this->assertStringContainsString("[aria-invalid='true']", $css);
        $this->assertStringContainsString('.fp-buscador', $css);
        $this->assertStringContainsString('::file-selector-button', $css);
    }
}
