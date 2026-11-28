<?php

namespace Tests\Feature\Diseno;

use Tests\TestCase;

/**
 * Liquid glass del sistema: vive solo en app.css (no en las vistas), arranca
 * sólido, el desenfoque va dentro de @supports, el texto sigue en AA y se apaga
 * con reduced-transparency, forced-colors y reduced-motion.
 */
class LiquidGlassSistemaTest extends TestCase
{
    private function bloque(): string
    {
        $hoja = (string) file_get_contents(base_path('resources/css/app.css'));

        return substr($hoja, (int) strpos($hoja, 'Liquid glass del sistema (estilo macOS)'));
    }

    public function test_tarjetas_pestanas_menus_y_modales_son_vidrio_de_la_marca(): void
    {
        $b = $this->bloque();

        $this->assertMatchesRegularExpression('/\.fp-tarjeta, \.fp-tarjeta-interactiva, \.fp-pestanas \{\s*background-color: rgb\(255 255 255 \/ \.8\)/', $b);
        $this->assertStringContainsString('linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%)', $b);
        $this->assertStringContainsString('rgb(59 130 246', $b);
        $this->assertMatchesRegularExpression('/@supports[^{]*\{\s*\.fp-modal, \.fp-menu-acciones \{\s*background-color: rgb\(255 255 255 \/ \.88\);[^}]*backdrop-filter: blur\(24px\) saturate\(1\.8\)/', $b);
    }

    public function test_las_tarjetas_no_llevan_desenfoque_para_no_atrapar_los_modales_fijos(): void
    {
        preg_match('/\.fp-tarjeta, \.fp-tarjeta-interactiva, \.fp-pestanas \{([^}]*)\}/', $this->bloque(), $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('backdrop-filter', $m[1]);
        $this->assertStringNotContainsString('backdrop-filter: blur', substr($this->bloque(), 0, (int) strpos($this->bloque(), '@supports ((')));
    }

    public function test_se_apaga_con_las_preferencias_de_accesibilidad(): void
    {
        $b = $this->bloque();

        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.fp-modal \{ animation: none !important; \}/', $b);
        $this->assertMatchesRegularExpression('/prefers-reduced-transparency: reduce\)\s*\{[^@]*\.fp-tarjeta[^@]*backdrop-filter: none !important;[^@]*background-color: #fff !important;/', $b);
        $this->assertMatchesRegularExpression('/forced-colors: active\)\s*\{[^@]*\.fp-modal[^@]*Canvas/', $b);
    }

    /** Peor caso: la marca más oscura (#111E38) detrás del vidrio flotante al .88. */
    public function test_el_texto_tenue_queda_en_aa_sobre_el_vidrio_flotante(): void
    {
        $lum = function (array $rgb) {
            $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $fondo = array_map(fn ($o) => 255 * .88 + $o * .12, [17, 30, 56]);

        $this->assertGreaterThanOrEqual(4.5, ($lum($fondo) + 0.05) / ($lum([88, 101, 121]) + 0.05));
    }
}
