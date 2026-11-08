<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * TG-356: importes en centavos enteros, sin floats.
 * Lee el texto decimal que regresa la base ("450.00") y lo vuelve a escribir.
 */
final class Centavos
{
    public static function desdeDecimal(string|int|null $valor): int
    {
        $texto = trim((string) ($valor ?? '0'));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $texto, $m)) {
            throw new InvalidArgumentException('Importe inválido: '.$texto);
        }
        $centavos = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$centavos : $centavos;
    }

    public static function aDecimal(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $signo.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
