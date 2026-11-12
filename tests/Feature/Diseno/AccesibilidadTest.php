<?php

namespace Tests\Feature\Diseno;

use Tests\TestCase;

/**
 * TG-381 A21 — Contraste AA y navegación con teclado.
 *
 * Las dos cosas que fija esta prueba son fáciles de deshacer sin querer: el
 * gris se "arregla" aclarándolo porque se ve más elegante, y el enlace de
 * salto se pierde al reacomodar un layout.
 */
class AccesibilidadTest extends TestCase
{
    /** @return array<string, string> */
    private static function layoutsConMenu(): array
    {
        return [
            'admin' => 'Admin',
            'panel' => 'Panel',
            'public' => 'Público',
            'tienda' => 'Tienda',
        ];
    }

    /**
     * El gris apagado tiene que pasar AA sobre el fondo de la página.
     *
     * Medido: #64748B (el de antes) daba 4.41:1 sobre #F5F6FA y AA pide 4.5.
     * Sobre blanco sí pasaba (4.76), que es justo por qué nadie lo notó:
     * fallaba fuera de las tarjetas. El de ahora da 5.47 y 5.91.
     */
    public function test_el_gris_apagado_pasa_aa_sobre_el_fondo_de_la_pagina(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match('/--color-fp-text-muted:\s*(#[0-9A-Fa-f]{6})/', $css, $m);
        $this->assertNotEmpty($m, 'No se encontró el token --color-fp-text-muted.');

        $contraste = $this->contraste($m[1], '#F5F6FA');

        $this->assertGreaterThanOrEqual(
            4.5,
            $contraste,
            "El gris apagado da {$contraste}:1 sobre el fondo de la página y AA pide 4.5."
        );
    }

    public function test_el_gris_apagado_tambien_pasa_sobre_blanco(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/--color-fp-text-muted:\s*(#[0-9A-Fa-f]{6})/', $css, $m);

        $this->assertGreaterThanOrEqual(4.5, $this->contraste($m[1], '#FFFFFF'));
    }

    /** No se toca la marca: ese era el límite que puso la tarjeta. */
    public function test_los_colores_de_la_marca_siguen_igual(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach ([
            '--color-fp-primary' => '#2563EB',
            '--color-fp-sidebar' => '#111E38',
            '--color-fp-accent' => '#1E2F52',
        ] as $token => $valor) {
            $this->assertStringContainsString("{$token}: {$valor};", $css, "Se movió {$token}, que es de la marca.");
        }
    }

    public function test_los_layouts_con_menu_tienen_el_enlace_de_salto(): void
    {
        foreach (self::layoutsConMenu() as $archivo => $nombre) {
            $vista = file_get_contents(resource_path("views/layouts/{$archivo}.blade.php"));

            $this->assertStringContainsString(
                '<x-ui.saltar-al-contenido />',
                $vista,
                "{$nombre}: sin el enlace hay que tabular por todo el menú en cada pantalla."
            );
        }
    }

    /**
     * El destino necesita tabindex="-1".
     *
     * Sin él, el navegador mueve el scroll pero NO el foco: el siguiente Tab
     * sigue desde el menú y el enlace no sirvió de nada.
     */
    public function test_el_destino_del_salto_puede_recibir_el_foco(): void
    {
        foreach (array_merge(self::layoutsConMenu(), ['guest' => 'Acceso']) as $archivo => $nombre) {
            $vista = file_get_contents(resource_path("views/layouts/{$archivo}.blade.php"));

            $this->assertMatchesRegularExpression(
                '/<main id="contenido" tabindex="-1"/',
                $vista,
                "{$nombre}: el destino del salto no puede recibir el foco."
            );
        }
    }

    /**
     * El enlace se ve con :focus, no solo con :focus-visible.
     *
     * Con focus-visible el navegador decide, y hay casos en que no lo pinta:
     * quedaría un enlace enfocado e invisible, peor que no tenerlo.
     */
    public function test_el_enlace_de_salto_aparece_al_enfocarlo(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.fp-saltar:focus {', $css);
        $this->assertStringNotContainsString('.fp-saltar:focus-visible {', $css);
    }

    /** Contraste WCAG entre dos colores en hexadecimal. */
    private function contraste(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $canal = function (float $c): float {
                $c /= 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            [$r, $g, $azul] = sscanf($hex, '#%02x%02x%02x');

            return 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($azul);
        };

        $x = $lum($a);
        $y = $lum($b);

        return round((max($x, $y) + 0.05) / (min($x, $y) + 0.05), 2);
    }
}
