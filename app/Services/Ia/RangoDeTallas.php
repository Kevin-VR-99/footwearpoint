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
        if (! $this->esNumero($desde) || ! $this->esNumero($hasta)) {
            return [];
        }

        $inicio = (float) $desde;
        $fin = (float) $hasta;

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

    private function esNumero(mixed $valor): bool
    {
        return is_numeric(is_string($valor) ? trim($valor) : $valor);
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
