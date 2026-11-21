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
     * TG-377 (A17) — Aire alrededor del recuadro que propone la IA.
     *
     * El prompt le pide el recuadro «pegado a su contorno» y, ante la duda,
     * que recorte DE MENOS. Esa instrucción existe para que no entre el
     * letrero del precio que suele ir al lado, y funciona: el problema es que
     * lo paga el borde del zapato, que sale cortado.
     *
     * En vez de pelearse con el prompt (que cuesta volver a leer el catálogo
     * entero), se le devuelve el aire aquí: 8% del tamaño del propio recuadro
     * por cada lado. Proporcional y no fijo, para que a un zapato chico le
     * toque poco aire y a uno grande más.
     *
     * Por qué 8 y no más: el letrero vive pegado a la foto, así que cada
     * punto de más acerca el recorte al texto. Ocho basta para descruzar un
     * contorno sin alcanzar el letrero; se midió antes de dejarlo (ver el
     * comando catalogo:medir-recortes).
     */
    private const MARGEN = 0.08;

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
