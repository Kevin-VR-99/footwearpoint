<?php

namespace App\Services\Ia;

/**
 * TG-240 (A10) — Convertir "22 AL 27" en la lista de tallas que toca crear.
 *
 * Los catálogos reales dan el rango, no la lista: Impuls dice "22 AL 27" y
 * Confort "2 - 6 enteros". De ahí salen las variantes del producto.
 *
 * Lo que NO hace: inventar tallas cuando el dato no se entiende. Un rango al
 * revés, con letras o absurdamente grande casi siempre es un error de
 * lectura de la IA, y es mejor que el producto se quede sin tallas y se
 * reporte, a llenarle el catálogo de basura a alguien.
 */
class RangoDeTallas
{
    /** Más de esto en un solo producto no es un rango: es un error de lectura. */
    private const MAXIMO_RAZONABLE = 40;

    /**
     * @return array<int, string> Los valores de talla, como los guarda la tabla.
     */
    public function valores(mixed $desde, mixed $hasta, bool $conMedios = false): array
    {
        $inicio = $this->aNumero($desde);
        $fin = $this->aNumero($hasta);

        if ($inicio === null || $fin === null) {
            return [];
        }

        // Una sola talla es válido; al revés no.
        if ($fin < $inicio) {
            return [];
        }

        $paso = $conMedios ? 0.5 : 1.0;
        $valores = [];

        for ($t = $inicio; $t <= $fin + 0.001; $t += $paso) {
            $valores[] = $this->comoTexto($t);

            if (count($valores) > self::MAXIMO_RAZONABLE) {
                return [];
            }
        }

        return $valores;
    }

    /**
     * La talla como número, o null si no se entiende.
     *
     * Los catálogos imprimen las medias tallas como fracción, no como
     * decimal: el Cklass dice "2 AL 6½". La IA lo transcribe fiel —"6 1/2"—
     * y eso no es un error suyo, así que el que tiene que entenderlo es
     * esto. Por no hacerlo, 15 productos de un catálogo real se quedaron
     * fuera con "no se entendió el rango de tallas".
     *
     * Se aceptan "6 1/2", "6½", "6.5", "6,5" y "1/2" suelto. Cualquier otra
     * cosa sigue devolviendo null: inventar una talla es peor que reportar.
     */
    private function aNumero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        // Los símbolos de fracción, a su forma escrita.
        $texto = strtr($texto, ['½' => ' 1/2', '¼' => ' 1/4', '¾' => ' 3/4', '⅓' => ' 1/3', '⅔' => ' 2/3']);

        // La coma decimal que se usa aquí.
        $texto = str_replace(',', '.', $texto);
        $texto = trim(preg_replace('/\s+/', ' ', $texto));

        // "6 1/2": entero más fracción.
        if (preg_match('#^(\d+)\s+(\d+)/(\d+)$#', $texto, $p) === 1 && (int) $p[3] !== 0) {
            return (float) $p[1] + (int) $p[2] / (int) $p[3];
        }

        // "1/2" suelto.
        if (preg_match('#^(\d+)/(\d+)$#', $texto, $p) === 1 && (int) $p[2] !== 0) {
            return (int) $p[1] / (int) $p[2];
        }

        return is_numeric($texto) ? (float) $texto : null;
    }

    /** 22.0 se guarda como "22" y 22.5 como "22.5", igual que en la tabla. */
    private function comoTexto(float $talla): string
    {
        $redondeada = round($talla, 1);

        return floor($redondeada) == $redondeada
            ? (string) (int) $redondeada
            : rtrim(rtrim(number_format($redondeada, 1, '.', ''), '0'), '.');
    }
}
