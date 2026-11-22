<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Log;

/**
 * TG-282 (E5-05) — Recortar la foto de un zapato de la página ya dibujada.
 *
 * La IA devuelve el recuadro en números de 0 a 1 respecto de la página, no en
 * pixeles, para que no dependa de a qué resolución se haya dibujado. Aquí se
 * convierten a pixeles y se recorta con GD, que ya está (ext-gd).
 *
 * Nada de lo que salga de aquí se publica solo: esto propone y una persona
 * confirma. Por eso, ante la duda, se devuelve null: el producto nace sin
 * foto y se sube a mano, que es peor que un recorte bueno pero mucho mejor
 * que publicar la foto del zapato de al lado.
 */
class RecortarFotoDelCatalogo
{
    /**
     * Un recuadro más chico que esto casi nunca es un zapato: es un logo, un
     * icono de "piel", o la IA apuntando a cualquier cosa. 6% de ancho o de
     * alto de la página.
     */
    private const MINIMO = 0.06;

    /**
     * TG-377 (A17) — Un poco de aire alrededor del recuadro de la IA.
     *
     * EL 3% ES UN COMPROMISO, NO UN ÓPTIMO, y conviene saber por qué antes de
     * subirlo o bajarlo.
     *
     * El prompt le pide a la IA el recuadro «pegado al contorno» y que, ante
     * la duda, recorte DE MENOS. En algunos productos eso corta el zapato: le
     * come la punta o la suela. Ese fue el reclamo que abrió esta tarjeta.
     *
     * Pero al mirar los recortes de producción (catalogo:contacto-recortes)
     * salió que los recuadros de la IA NO fallan todos para el mismo lado:
     *
     *   - En unos viene apretado y corta el zapato. Ahí hacen falta 10% o 15%
     *     de aire para que quepa entero.
     *   - En otros ya viene pasado y el letrero del precio está DENTRO desde
     *     el margen 0. Ahí cada punto de aire mete más letrero y más logos.
     *
     * O sea que ningún margen global arregla los dos: el que salva al primero
     * arruina al segundo. Por eso se queda chico — ayuda algo al apretado sin
     * empeorar de más al pasado — y el arreglo de verdad es corregir a mano
     * desde «Ajustar recorte», que es producto por producto.
     *
     * OJO si alguien quiere «medirlo bien»: hubo una versión de esta tarjeta
     * que lo midió con un porcentaje (cuánto del marco no era del color del
     * fondo) y mintió dos veces, porque estas páginas traen dos fondos y la
     * frontera entre ellos se contaba como si fuera el zapato. La medición
     * que sirve son las hojas de contacto, mirándolas.
     */
    private const MARGEN = 0.03;

    /**
     * Devuelve la ruta del PNG recortado, o null si el recuadro no sirve.
     *
     * Quien llama se encarga de borrar el archivo.
     *
     * @param  array<string, mixed>  $datos  lo que leyó la IA de ese producto
     */
    public function recortar(string $rutaPagina, array $datos, ?float $margen = null): ?string
    {
        $caja = $this->cajaFinal($datos, $margen);

        if ($caja === null) {
            return null;
        }

        [$x, $y, $ancho, $alto] = $caja;

        $pagina = @imagecreatefrompng($rutaPagina);

        if ($pagina === false) {
            return null;
        }

        try {
            $anchoPagina = imagesx($pagina);
            $altoPagina = imagesy($pagina);

            $recorte = imagecrop($pagina, [
                'x'      => (int) round($x * $anchoPagina),
                'y'      => (int) round($y * $altoPagina),
                'width'  => (int) round($ancho * $anchoPagina),
                'height' => (int) round($alto * $altoPagina),
            ]);

            if ($recorte === false) {
                return null;
            }

            try {
                $destino = tempnam(sys_get_temp_dir(), 'recorte');

                if ($destino === false) {
                    return null;
                }

                // tempnam deja un archivo sin extensión; GD necesita que el
                // nombre termine en .png para que después se suba bien.
                @unlink($destino);
                $destino .= '.png';

                if (! imagepng($recorte, $destino)) {
                    @unlink($destino);

                    return null;
                }

                return $destino;
            } finally {
                imagedestroy($recorte);
            }
        } finally {
            imagedestroy($pagina);
        }
    }

    /**
     * El recuadro con su aire, sin salirse de la página.
     *
     * Va DESPUÉS de los rechazos a propósito: un recuadro demasiado chico se
     * descarta por lo que la IA dijo, no por lo que mida después de inflarlo.
     * Si no fuera así, el aire rescataría recuadros que apuntan a un logo.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function conAire(float $x, float $y, float $ancho, float $alto, float $margen): array
    {
        if ($margen <= 0.0) {
            return [$x, $y, $ancho, $alto];
        }

        $aireX = $ancho * $margen;
        $aireY = $alto * $margen;

        // Los bordes de la página mandan: si el zapato ya estaba pegado al
        // borde, por ese lado no hay aire que darle.
        $derecha = min(1.0, $x + $ancho + $aireX);
        $abajo = min(1.0, $y + $alto + $aireY);
        $x = max(0.0, $x - $aireX);
        $y = max(0.0, $y - $aireY);

        return [$x, $y, $derecha - $x, $abajo - $y];
    }

    /**
     * El recuadro final, ya comprobado y con su aire, o null.
     *
     * Es público porque el editor de recortes (TG-377) tiene que arrancar
     * EXACTAMENTE del mismo recuadro que produjo la foto que se está viendo.
     * Si arrancara del crudo de la IA, el recuadro saldría más apretado que
     * la foto de al lado y nadie entendería por qué.
     *
     * Se revisa de verdad y no por desconfianza teórica: son cuatro números
     * que llegan de un modelo, y un recuadro que se sale de la página o que
     * mide cero haría que imagecrop devolviera basura o nada.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function cajaFinal(array $datos, ?float $margen = null): ?array
    {
        $valores = [];

        foreach (['foto_x', 'foto_y', 'foto_ancho', 'foto_alto'] as $campo) {
            $valor = $datos[$campo] ?? null;

            if (! is_int($valor) && ! is_float($valor)) {
                return null;
            }

            $valores[] = (float) $valor;
        }

        [$x, $y, $ancho, $alto] = $valores;

        if ($x < 0 || $y < 0 || $ancho <= 0 || $alto <= 0) {
            return null;
        }

        if ($ancho < self::MINIMO || $alto < self::MINIMO) {
            Log::info('Recuadro demasiado chico para ser un zapato; el producto queda sin foto', [
                'ancho' => $ancho,
                'alto'  => $alto,
            ]);

            return null;
        }

        // Se sale de la hoja. Se recorta al borde en vez de descartarlo: la
        // IA tiende a pasarse un poco, y perder una foto buena por dos
        // centésimas sería tonto. Si se pasa mucho, lo de abajo lo tira.
        $ancho = min($ancho, 1.0 - $x);
        $alto = min($alto, 1.0 - $y);

        if ($ancho < self::MINIMO || $alto < self::MINIMO) {
            return null;
        }

        return $this->conAire($x, $y, $ancho, $alto, $margen ?? self::MARGEN);
    }
}
