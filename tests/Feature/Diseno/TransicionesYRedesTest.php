<?php

namespace Tests\Feature\Diseno;

use Tests\TestCase;

/**
 * TG-392 (A32) — Transiciones cortas, movimiento reducido y los iconos de
 * «¿Hablamos?» con el color de su red.
 *
 * Lo que vigila esto es sobre todo que no se deshaga: son clases de estilo y
 * es facilísimo que alguien vuelva a poner una utilidad suelta sin darse
 * cuenta de que deja fuera a quien pidió movimiento reducido.
 */
class TransicionesYRedesTest extends TestCase
{
    private const INICIO = 'resources/views/components/publico/⚡inicio.blade.php';

    private function hoja(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }

    /** Sin los comentarios de Blade: ahí se explica lo que se quitó. */
    private function vista(string $ruta): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($ruta)));
    }

    // --- Movimiento reducido -------------------------------------------------

    /**
     * La red de seguridad que faltaba.
     *
     * Los bloques que ya existían apagan clases fp-* concretas. Las pantallas
     * públicas usan además utilidades sueltas de Tailwind, y esas no las
     * apagaba nadie: quien pedía movimiento reducido lo seguía teniendo.
     */
    public function test_el_movimiento_reducido_cubre_tambien_las_utilidades_sueltas(): void
    {
        $hoja = $this->hoja();

        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\*,\s*\*::before,\s*\*::after\s*\{/',
            $hoja,
            'Se fue la regla global de movimiento reducido: las utilidades sueltas vuelven a animarse.'
        );

        foreach (['animation-duration', 'transition-duration', 'scroll-behavior'] as $propiedad) {
            $this->assertStringContainsString($propiedad.':', $hoja);
        }
    }

    /** Los iconos nuevos también se apagan, incluido el crecimiento. */
    public function test_los_iconos_de_redes_respetan_el_movimiento_reducido(): void
    {
        $hoja = $this->hoja();

        $this->assertMatchesRegularExpression('/\.fp-red\s*\{\s*transition:\s*none\s*!important/', $hoja);
        $this->assertMatchesRegularExpression('/\.fp-red:hover[^{]*\{\s*transform:\s*none\s*!important/', $hoja);
    }

    // --- Transiciones cortas --------------------------------------------------

    /**
     * «Transiciones cortas» es lo que pide la tarjeta.
     *
     * 300ms se siente lento en algo que se usa a cada rato; 200 ya no. El
     * número exacto importa menos que no volver a 300 sin pensarlo.
     */
    public function test_ninguna_pantalla_publica_se_quedo_en_300ms(): void
    {
        foreach ([
            self::INICIO => 'Inicio público',
            'resources/views/components/marketplace/⚡index.blade.php' => 'Marketplace',
            'resources/views/components/tienda/tarjeta-producto.blade.php' => 'Tarjeta de producto',
        ] as $ruta => $pantalla) {
            $this->assertStringNotContainsString(
                'duration-300',
                $this->vista($ruta),
                "{$pantalla}: volvió una transición de 300ms."
            );
        }
    }

    // --- Los iconos -----------------------------------------------------------

    public function test_cada_icono_lleva_la_clase_de_su_red(): void
    {
        $vista = $this->vista(self::INICIO);

        $this->assertStringContainsString('fp-red fp-red-{{ $red }}', $vista, 'Los iconos ya no toman la clase de su red.');
        $this->assertStringContainsString('fp-red fp-red-correo', $vista);
    }

    /**
     * Cada red con su color, no las cuatro en blanco.
     *
     * Antes el hover dejaba los cuatro iguales: el mismo gesto para Instagram
     * que para el correo. El color de marca es lo que se reconoce sin leer.
     */
    public function test_cada_red_tiene_el_color_de_su_marca(): void
    {
        $hoja = $this->hoja();

        foreach ([
            'instagram' => '#E4405F',
            'facebook' => '#1877F2',
            'youtube' => '#FF0000',
        ] as $red => $color) {
            $this->assertMatchesRegularExpression(
                '/\.fp-red-'.$red.':hover[^}]*background-color:\s*'.preg_quote($color, '/').'/i',
                $hoja,
                "El icono de {$red} perdió su color de marca."
            );
        }

        $this->assertMatchesRegularExpression(
            '/\.fp-red-instagram:hover[^}]*background-image:\s*(radial|linear)-gradient/s',
            $hoja,
            'Instagram tiene que llevar su degradado de marca.'
        );
        $this->assertMatchesRegularExpression(
            '/\.fp-red:hover[^}]*transform:\s*scale\(1\.12\)/s',
            $hoja,
            'Los iconos tienen que agrandarse un poco al pasar el cursor.'
        );
    }

    /**
     * El foco hace lo mismo que el cursor.
     *
     * Antes solo había :hover, así que quien navega con teclado no recibía
     * ninguna señal al llegar a los iconos. En A21 se arregló esto en el
     * panel y estos se habían quedado fuera.
     */
    public function test_los_iconos_responden_al_teclado_y_no_solo_al_cursor(): void
    {
        $hoja = $this->hoja();

        $this->assertStringContainsString('.fp-red:focus-visible', $hoja, 'Los iconos volvieron a responder solo al cursor.');

        foreach (['instagram', 'facebook', 'youtube', 'correo'] as $red) {
            $this->assertStringContainsString('.fp-red-'.$red.':focus-visible', $hoja);
        }
    }

    /** El objetivo táctil ya estaba bien y no se toca. */
    public function test_los_iconos_siguen_midiendo_44_pixeles(): void
    {
        $this->assertSame(
            2,
            substr_count($this->vista(self::INICIO), 'h-11 w-11'),
            'Cambió el tamaño de los iconos: 44px es el mínimo para tocarlos con el dedo.'
        );
    }

    // --- Lo que la tarjeta prohíbe --------------------------------------------

    /**
     * «Sin nuevas redes ni cambiar href de $redes», dice la tarjeta.
     *
     * Es fácil que al tocar los estilos se cuele una red más o se escriba una
     * dirección a mano, y eso ya no sería un cambio de diseño.
     */
    public function test_no_se_agregaron_redes_ni_se_escribieron_direcciones_a_mano(): void
    {
        $vista = $this->vista(self::INICIO);

        $this->assertStringContainsString("['instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube']", $vista);
        $this->assertStringContainsString('href="{{ $redes[$red] }}"', $vista);
        $this->assertStringContainsString('href="mailto:{{ $redes[\'correo\'] }}"', $vista);

        foreach (['tiktok', 'twitter', 'x.com', 'linkedin', 'threads'] as $otra) {
            $this->assertStringNotContainsString($otra, mb_strtolower($vista), "Se agregó {$otra}, y la tarjeta lo prohíbe.");
        }
    }

    // --- La pantalla sigue de pie ---------------------------------------------

    public function test_el_inicio_publico_sigue_abriendo(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('¿Hablamos?');
    }
}
