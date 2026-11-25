<?php

namespace Tests\Feature\Diseno;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-389 (A29) — Liquid glass solo como acento, con fondo sólido de respaldo.
 *
 * El glass vive solo en .fp-vidrio y .fp-vidrio-oscuro, y solo en la barra
 * superior, los desplegables y el botón secundario del inicio. Lo demás
 * (datos, formas, tablas, precios, modales, Pedidos/Stock/Venta/pagos) es
 * sólido. Esto vigila que no se deshaga.
 */
class VidrioComoAcentoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function hoja(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }

    /** Sin los comentarios de Blade, donde se explica lo que se quitó. */
    private function vista(string $ruta): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/'.$ruta)));
    }

    /** El bloque de TG-389 al final de la hoja. */
    private function bloque(): string
    {
        $hoja = $this->hoja();

        return substr($hoja, (int) strpos($hoja, 'TG-389 (A29) — Liquid glass'));
    }

    // --- El sistema --------------------------------------------------------

    public function test_cada_clase_arranca_solida_y_el_blur_solo_entra_con_supports(): void
    {
        $bloque = $this->bloque();

        $this->assertMatchesRegularExpression('/\.fp-vidrio\s*\{\s*background-color:\s*#fff;\s*\}/', $bloque, 'Sin respaldo sólido para .fp-vidrio.');
        $this->assertMatchesRegularExpression('/\.fp-vidrio-oscuro\s*\{\s*background-color:[^;]+;\s*\}/', $bloque);

        $supports = strpos($bloque, '@supports ((');
        $this->assertNotFalse($supports, 'El blur ya no está detrás de @supports.');
        $this->assertLessThan($supports, strpos($bloque, '.fp-vidrio { background-color: #fff; }'), 'El sólido tiene que ir antes que el translúcido.');

        // Antes del @supports no hay ningún blur: el navegador que no lo soporta se queda con el sólido.
        $this->assertStringNotContainsString('backdrop-filter: blur', substr($bloque, 0, (int) strpos($bloque, '@supports ((')));
    }

    public function test_menos_transparencia_y_colores_forzados_vuelven_al_solido(): void
    {
        $bloque = $this->bloque();

        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-transparency:\s*reduce\)\s*\{[^@]*backdrop-filter:\s*none\s*!important[^@]*\.fp-vidrio\s*\{\s*background-color:\s*#fff\s*!important/s', $bloque);
        $this->assertMatchesRegularExpression('/@media\s*\(forced-colors:\s*active\)\s*\{[^@]*backdrop-filter:\s*none\s*!important[^@]*Canvas/s', $bloque);
    }

    /**
     * El translúcido sigue siendo casi blanco: con el azul de la marca
     * detrás, el gris tenue de la casa todavía pasa AA (4.5:1).
     */
    public function test_el_translucido_deja_el_texto_tenue_en_aa_aun_con_fondo_oscuro(): void
    {
        preg_match('/@supports[^{]*\{\s*\.fp-vidrio\s*\{\s*background-color:\s*rgb\(255 255 255 \/ \.(\d+)\)/', $this->bloque(), $m);
        $this->assertNotEmpty($m, 'No se encontró la opacidad del translúcido.');
        $opacidad = (float) ('0.'.$m[1]);

        $hex = fn (string $h) => array_map(fn ($i) => hexdec(substr($h, $i, 2)), [1, 3, 5]);
        $lum = function (array $rgb) {
            $c = array_map(function ($v) {
                $v /= 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, $rgb);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };

        // Peor caso: fp-sidebar (#111E38) completo detrás del vidrio.
        $fondo = array_map(fn ($o) => 255 * $opacidad + $o * (1 - $opacidad), $hex('#111E38'));
        $texto = $hex('#586579'); // --color-fp-text-muted

        $contraste = ($lum($fondo) + 0.05) / ($lum($texto) + 0.05);

        $this->assertGreaterThanOrEqual(4.5, $contraste, "Con {$opacidad} el texto tenue queda en {$contraste}:1.");
    }

    // --- Dónde sí hay glass -----------------------------------------------

    public function test_las_cuatro_barras_superiores_usan_el_vidrio_del_sistema(): void
    {
        foreach (['layouts/admin', 'layouts/panel', 'layouts/public', 'layouts/tienda'] as $layout) {
            $vista = $this->vista($layout.'.blade.php');

            $this->assertMatchesRegularExpression('/sticky top-0 z-30[^"]*\bfp-vidrio\b/', $vista, "{$layout}: la barra superior perdió .fp-vidrio.");
            $this->assertStringNotContainsString('backdrop-blur', $vista, "{$layout}: volvió un blur suelto.");
        }
    }

    public function test_los_desplegables_y_el_detalle_del_inicio_son_el_acento(): void
    {
        $this->assertStringContainsString('fp-vidrio shadow-lg', $this->vista('components/panel/menu-usuario.blade.php'));
        $this->assertStringContainsString('fp-vidrio shadow-lg', $this->vista('components/notificaciones/⚡nav-badge.blade.php'));
        $this->assertStringContainsString('fp-vidrio-oscuro', $this->vista('components/publico/⚡inicio.blade.php'));
    }

    // --- Dónde no -----------------------------------------------------------

    /** Ninguna vista trae su propio blur: el glass solo entra por las dos clases. */
    public function test_ninguna_vista_usa_blur_suelto(): void
    {
        $conBlur = [];
        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($archivos as $archivo) {
            if (! str_ends_with($archivo->getFilename(), '.blade.php')) {
                continue;
            }
            $contenido = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($archivo->getPathname()));
            if (preg_match('/backdrop-blur|backdrop-filter|supports-\[backdrop/', $contenido)) {
                $conBlur[] = str_replace(resource_path('views'), '', $archivo->getPathname());
            }
        }

        $this->assertSame([], $conBlur, 'Estas vistas traen blur propio; usa .fp-vidrio solo si es un acento.');
    }

    public function test_los_modales_y_la_carga_tapan_con_un_velo_solido(): void
    {
        $this->assertMatchesRegularExpression('/\.fp-modal-fondo\s*\{[^}]*\}/', $this->hoja());
        preg_match('/\.fp-modal-fondo\s*\{([^}]*)\}/', $this->hoja(), $regla);
        $this->assertStringNotContainsString('backdrop-filter', $regla[1], 'El fondo del modal volvió a ser glass.');

        // La carga se pone encima de tablas de Pedidos, Stock y Venta.
        $this->assertStringNotContainsString('backdrop', $this->vista('components/ui/cargando.blade.php'));
    }

    public function test_el_buscador_del_marketplace_es_solido(): void
    {
        $vista = $this->vista('components/marketplace/⚡index.blade.php');

        preg_match('/<form class="(sticky top-\[4\.125rem\][^"]*)"/', $vista, $m);
        $this->assertNotEmpty($m, 'No se encontró el buscador pegado arriba.');
        $this->assertMatchesRegularExpression('/(^|\s)bg-fp-page(\s|$)/', $m[1], 'El buscador tiene que tener fondo sólido.');
        $this->assertDoesNotMatchRegularExpression('/bg-fp-page\/\d+|backdrop/', $m[1]);
    }

    // --- Las pantallas siguen de pie --------------------------------------

    public function test_las_pantallas_publicas_abren_con_el_vidrio_en_la_barra(): void
    {
        $this->withoutVite();

        foreach (['/', '/marketplace'] as $ruta) {
            $this->get($ruta)->assertOk()->assertSee('fp-vidrio shadow-sm', false);
        }

        $this->get('/')->assertOk()->assertSee('fp-vidrio-oscuro', false);
    }
}
