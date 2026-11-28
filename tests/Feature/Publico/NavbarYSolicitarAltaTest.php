<?php

namespace Tests\Feature\Publico;

use Tests\TestCase;

class NavbarYSolicitarAltaTest extends TestCase
{
    public function test_los_layouts_publicos_fijan_la_navbar_arriba(): void
    {
        $public = file_get_contents(resource_path('views/layouts/public.blade.php'));
        $tienda = file_get_contents(resource_path('views/layouts/tienda.blade.php'));

        $this->assertStringContainsString('fp-pagina-publica', $public);
        $this->assertStringContainsString('fp-navbar-publica', $public);
        $this->assertMatchesRegularExpression('/sticky top-0 z-30[^"]*\bfp-vidrio\b/', $public);

        $this->assertStringContainsString('fp-pagina-publica', $tienda);
        $this->assertStringContainsString('fp-navbar-publica', $tienda);
        $this->assertMatchesRegularExpression('/sticky top-0 z-30[^"]*\bfp-vidrio\b/', $tienda);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('--fp-navbar-alto: 4.125rem', $css);
        $this->assertMatchesRegularExpression('/\.fp-navbar-publica\s*\{[^}]*position:\s*fixed/s', $css);
        $this->assertMatchesRegularExpression('/\.fp-navbar-publica\s*\{[^}]*top:\s*0/s', $css);
        $this->assertStringContainsString('body.fp-pagina-publica { padding-top: var(--fp-navbar-alto); }', $css);
        $this->assertStringContainsString('html:has(body.fp-pagina-publica)', $css);
        $this->assertStringContainsString('scroll-padding-top', $css);
        $this->assertMatchesRegularExpression('/\.fp-saltar\s*\{[^}]*position:\s*fixed/s', $css);
    }

    public function test_solicitar_alta_usa_la_presentacion_de_marca(): void
    {
        $vistas = glob(resource_path('views/components/publico/*solicitar-alta.blade.php'));
        $this->assertNotEmpty($vistas);
        $vista = file_get_contents($vistas[0]);

        $this->assertStringContainsString('fp-alta', $vista);
        $this->assertStringContainsString('fp-alta-encabezado', $vista);
        $this->assertStringContainsString('fp-marca-luminosa', $vista);
        $this->assertStringContainsString('fp-alta-tarjeta', $vista);
        $this->assertStringContainsString('fp-alta-icono', $vista);
        $this->assertStringContainsString('wire:model="nombre_comercial"', $vista);
        $this->assertStringContainsString('wire:model="razon_social"', $vista);
        $this->assertStringContainsString('admin_password', $vista);
        $this->assertStringContainsString('wire:submit="enviar"', $vista);
        $this->assertStringContainsString('Recibimos tu solicitud', $vista);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.fp-alta-tarjeta', $css);
        $this->assertStringContainsString('backdrop-filter', $css);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('.fp-alta-encabezado, .fp-alta-tarjeta', $css);
        $this->assertDoesNotMatchRegularExpression('/backdrop-blur/', $vista);
    }
}
