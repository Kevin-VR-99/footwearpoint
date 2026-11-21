<?php

namespace App\Services\Ia;

/**
 * TG-377 (A17) — Cuánto se corta un zapato en su recorte.
 *
 * LA PREGUNTA QUE RESPONDE: ¿el zapato cabe entero o lo partieron?
 *
 * La idea es simple: si el zapato cabe, alrededor suyo hay fondo, y el marco
 * del recorte es todo fondo. Si lo cortaron, su silueta cruza el marco y ahí
 * aparecen píxeles que no son fondo. Contarlos da un número que se puede
 * comparar antes y después, en vez de discutir si «se ve mejor».
 *
 * El fondo NO se da por blanco: las páginas de los catálogos traen fondos de
 * color, degradados y bloques. Se toma de las esquinas de la propia página,
 * que es donde casi nunca hay producto.
 */
class MedirRecorte
{
    /**
     * Cuánto se puede separar un píxel del fondo y seguir contando como fondo.
     *
     * Las páginas vienen de un PDF dibujado, así que un fondo «liso» igual
     * trae ruido de compresión y sombras suaves. Con menos tolerancia se
     * contarían sombras como si fueran el zapato.
     */
    private const TOLERANCIA = 42;

    /**
     * El color de fondo de una página, sacado de sus cuatro esquinas.
     *
     * Se usa la mediana y no el promedio: si una esquina cae dentro de un
     * recuadro de color, el promedio se va con ella y la mediana no.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function fondoDeLaPagina(string $rutaPng): array
    {
        $imagen = @imagecreatefrompng($rutaPng);

        if ($imagen === false) {
            return [255, 255, 255];
        }

        try {
            $ancho = imagesx($imagen) - 1;
            $alto = imagesy($imagen) - 1;
            $canales = [[], [], []];

            foreach ([[0, 0], [$ancho, 0], [0, $alto], [$ancho, $alto]] as [$x, $y]) {
                $c = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));
                $canales[0][] = $c['red'];
                $canales[1][] = $c['green'];
                $canales[2][] = $c['blue'];
            }

            return array_map(function (array $valores): int {
                sort($valores);

                return (int) round(($valores[1] + $valores[2]) / 2);
            }, $canales);
        } finally {
            imagedestroy($imagen);
        }
    }

    /**
     * Píxeles del marco del recorte que no son fondo.
     *
     * 0 significa que el zapato cabe entero. Cuanto más alto, más silueta
     * quedó cruzando el borde, o sea más cortado está.
     *
     * @param  array{0: int, 1: int, 2: int}|null  $fondo  el de la página; blanco si no se da
     */
    public function siluetaEnElBorde(string $rutaPng, ?array $fondo = null): int
    {
        $imagen = @imagecreatefrompng($rutaPng);

        if ($imagen === false) {
            return 0;
        }

        $fondo ??= [255, 255, 255];

        try {
            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);
            $cuantos = 0;

            $esSilueta = function (int $x, int $y) use ($imagen, $fondo): bool {
                $c = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));

                return abs($c['red'] - $fondo[0]) > self::TOLERANCIA
                    || abs($c['green'] - $fondo[1]) > self::TOLERANCIA
                    || abs($c['blue'] - $fondo[2]) > self::TOLERANCIA;
            };

            for ($x = 0; $x < $ancho; $x++) {
                $cuantos += (int) $esSilueta($x, 0) + (int) $esSilueta($x, $alto - 1);
            }

            // Sin las esquinas, que ya se contaron arriba.
            for ($y = 1; $y < $alto - 1; $y++) {
                $cuantos += (int) $esSilueta(0, $y) + (int) $esSilueta($ancho - 1, $y);
            }

            return $cuantos;
        } finally {
            imagedestroy($imagen);
        }
    }

    /**
     * Lo mismo, pero en porcentaje del marco.
     *
     * El número crudo depende del tamaño del recorte, así que no sirve para
     * comparar un zapato chico con uno grande. El porcentaje sí.
     */
    public function porcentajeEnElBorde(string $rutaPng, ?array $fondo = null): float
    {
        $imagen = @imagecreatefrompng($rutaPng);

        if ($imagen === false) {
            return 0.0;
        }

        $marco = 2 * (imagesx($imagen) + imagesy($imagen)) - 4;
        imagedestroy($imagen);

        if ($marco <= 0) {
            return 0.0;
        }

        return round(100 * $this->siluetaEnElBorde($rutaPng, $fondo) / $marco, 1);
    }
}
