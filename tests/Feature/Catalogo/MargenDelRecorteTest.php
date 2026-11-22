<?php

namespace Tests\Feature\Catalogo;

use App\Services\Ia\RecortarFotoDelCatalogo;
use Tests\TestCase;

/**
 * TG-377 (A17) — El zapato no sale cortado.
 *
 * NO HACE FALTA NI PDF NI IA. Se dibuja aquí una página de mentiras con un
 * «zapato» y su letrero al lado, y se recorta con recuadros conocidos. Así se
 * puede comprobar el comportamiento sin poppler (que en local no está) y sin
 * gastar un centavo de API.
 *
 * La medida de aquí (si la silueta toca el borde, el zapato quedó cortado)
 * solo vale sobre esta página de mentiras, que tiene un fondo blanco parejo.
 * Sobre los catálogos de verdad no sirve, y por eso no vive en app/: ver el
 * comentario de pixelesEnElBorde() más abajo. Para juzgar recortes reales
 * está catalogo:contacto-recortes, que saca las imágenes para mirarlas.
 */
class MargenDelRecorteTest extends TestCase
{
    private const ANCHO = 1000;

    private const ALTO = 1000;

    /** Dónde queda el «zapato» en la página, en fracciones de 0 a 1. */
    private const ZAPATO = ['x' => 0.30, 'y' => 0.30, 'ancho' => 0.20, 'alto' => 0.20];

    private string $pagina;

    /** @var list<string> */
    private array $basura = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pagina = $this->dibujarPagina();
        $this->basura[] = $this->pagina;
    }

    protected function tearDown(): void
    {
        foreach ($this->basura as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    /**
     * Una página con un zapato (elipse negra) y, pegado a su derecha, el
     * letrero del precio (una barra gris). El letrero importa: es lo que el
     * recorte NO debe tragarse al darle aire al zapato.
     */
    private function dibujarPagina(): string
    {
        $hoja = imagecreatetruecolor(self::ANCHO, self::ALTO);
        imagefill($hoja, 0, 0, imagecolorallocate($hoja, 255, 255, 255));

        $x = (int) (self::ZAPATO['x'] * self::ANCHO);
        $y = (int) (self::ZAPATO['y'] * self::ALTO);
        $ancho = (int) (self::ZAPATO['ancho'] * self::ANCHO);
        $alto = (int) (self::ZAPATO['alto'] * self::ALTO);

        imagefilledellipse($hoja, $x + intdiv($ancho, 2), $y + intdiv($alto, 2), $ancho, $alto,
            imagecolorallocate($hoja, 0, 0, 0));

        // El letrero, a 6% de distancia del zapato: más lejos que el aire que
        // damos, para que un margen razonable no lo alcance.
        $hueco = (int) (0.06 * self::ANCHO);
        imagefilledrectangle($hoja, $x + $ancho + $hueco, $y, $x + $ancho + $hueco + 80, $y + 40,
            imagecolorallocate($hoja, 128, 128, 128));

        $destino = tempnam(sys_get_temp_dir(), 'pagina').'.png';
        imagepng($hoja, $destino);
        imagedestroy($hoja);

        return $destino;
    }

    /** El recuadro exacto del zapato: lo que pide el prompt, «pegado al contorno». */
    private function recuadroPegado(): array
    {
        return [
            'foto_x' => self::ZAPATO['x'],
            'foto_y' => self::ZAPATO['y'],
            'foto_ancho' => self::ZAPATO['ancho'],
            'foto_alto' => self::ZAPATO['alto'],
        ];
    }

    private function recortar(array $datos, ?float $margen = null): string
    {
        $ruta = app(RecortarFotoDelCatalogo::class)->recortar($this->pagina, $datos, $margen);

        $this->assertNotNull($ruta, 'El recorte se descartó cuando debía salir.');
        $this->basura[] = $ruta;

        return $ruta;
    }

    /**
     * Cuántos píxeles del marco no son el fondo blanco de esta página.
     *
     * VIVE AQUÍ Y NO EN app/ A PROPÓSITO. Esta cuenta solo es de fiar sobre la
     * página de mentiras que dibuja esta prueba, que tiene un fondo blanco
     * parejo. Hubo una versión en producción y mintió: las páginas de verdad
     * traen dos fondos (una mitad gris, otra blanca) y la frontera entre
     * ellos se contaba como si fuera el zapato. Sacarla de app/ evita que
     * alguien la vuelva a usar creyendo que mide recortes reales.
     *
     * Para juzgar recortes de verdad está catalogo:contacto-recortes, que
     * saca las imágenes para mirarlas.
     */
    private function pixelesEnElBorde(string $png): int
    {
        $imagen = imagecreatefrompng($png);
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $cuantos = 0;

        $noEsFondo = function (int $x, int $y) use ($imagen): bool {
            $c = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));

            return ($c['red'] + $c['green'] + $c['blue']) / 3 < 200;
        };

        for ($x = 0; $x < $ancho; $x++) {
            $cuantos += (int) $noEsFondo($x, 0) + (int) $noEsFondo($x, $alto - 1);
        }

        for ($y = 1; $y < $alto - 1; $y++) {
            $cuantos += (int) $noEsFondo(0, $y) + (int) $noEsFondo($ancho - 1, $y);
        }

        imagedestroy($imagen);

        return $cuantos;
    }

    // --- Lo que la tarjeta viene a arreglar ---------------------------------

    /** Sin aire, el recuadro «pegado al contorno» corta el zapato. */
    public function test_sin_margen_la_silueta_toca_el_borde(): void
    {
        $this->assertGreaterThan(
            0,
            $this->pixelesEnElBorde($this->recortar($this->recuadroPegado(), 0.0)),
            'Sin margen el zapato debería salir tocando el borde; si no, la prueba no mide lo que cree.'
        );
    }

    /** Con el aire de siempre, ya no lo toca. Esto es el «después». */
    public function test_con_el_margen_de_la_casa_el_zapato_cabe_entero(): void
    {
        $this->assertSame(
            0,
            $this->pixelesEnElBorde($this->recortar($this->recuadroPegado())),
            'El zapato sigue tocando el borde del recorte.'
        );
    }

    /** Y el letrero del precio se queda fuera, que era el motivo del recorte apretado. */
    public function test_el_margen_no_se_traga_el_letrero(): void
    {
        $recorte = $this->recortar($this->recuadroPegado());
        $imagen = imagecreatefrompng($recorte);
        $gris = 0;

        for ($x = 0; $x < imagesx($imagen); $x++) {
            for ($y = 0; $y < imagesy($imagen); $y++) {
                $c = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));
                // El letrero es gris parejo; el zapato es negro.
                if (abs($c['red'] - 128) < 30 && abs($c['green'] - 128) < 30 && abs($c['blue'] - 128) < 30) {
                    $gris++;
                }
            }
        }

        imagedestroy($imagen);

        $this->assertSame(0, $gris, 'El aire alcanzó el letrero del precio: hay que bajar el margen.');
    }

    // --- Lo que el margen NO debe romper ------------------------------------

    /**
     * Un recuadro diminuto se sigue descartando.
     *
     * Si el aire se aplicara antes del rechazo, un recuadro que apunta a un
     * logo pasaría el mínimo solo por haberlo inflado, y se publicaría la foto
     * equivocada.
     */
    public function test_el_margen_no_rescata_un_recuadro_demasiado_chico(): void
    {
        $this->assertNull(
            app(RecortarFotoDelCatalogo::class)->recortar($this->pagina, [
                'foto_x' => 0.5, 'foto_y' => 0.5, 'foto_ancho' => 0.05, 'foto_alto' => 0.05,
            ]),
            'Un recuadro por debajo del mínimo pasó porque el margen lo infló.'
        );
    }

    /** Pegado al borde de la hoja, el aire se da donde cabe y no se inventa página. */
    public function test_un_zapato_en_la_esquina_no_se_sale_de_la_pagina(): void
    {
        $recorte = $this->recortar([
            'foto_x' => 0.0, 'foto_y' => 0.0, 'foto_ancho' => 0.20, 'foto_alto' => 0.20,
        ]);

        $imagen = imagecreatefrompng($recorte);
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        imagedestroy($imagen);

        // Por arriba y por la izquierda no hay aire que dar; por los otros dos
        // lados sí. De ahí que crezca, pero menos del doble del margen.
        $this->assertGreaterThan(0.20 * self::ANCHO, $ancho);
        $this->assertLessThanOrEqual((int) ceil(0.20 * (1 + 0.03) * self::ANCHO), $ancho);
        $this->assertGreaterThan(0.20 * self::ALTO, $alto);
    }

    /** El margen se puede variar para medirlo, que es lo que pide la tarjeta. */
    public function test_se_puede_medir_con_distintos_margenes(): void
    {
        $sinAire = $this->pixelesEnElBorde($this->recortar($this->recuadroPegado(), 0.0));
        $pocoAire = $this->pixelesEnElBorde($this->recortar($this->recuadroPegado(), 0.01));
        $harto = $this->pixelesEnElBorde($this->recortar($this->recuadroPegado(), 0.20));

        $this->assertGreaterThan($pocoAire, $sinAire, 'Más aire debería cortar menos, no más.');
        $this->assertSame(0, $harto);
    }
}
