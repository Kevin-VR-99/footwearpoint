<?php

namespace App\Support;

/**
 * CLABE de 18 dígitos. El dígito verificador usa los pesos 3, 7 y 1
 * sobre los primeros 17. Cada producto se toma módulo 10, como define
 * el algoritmo de la CLABE, y el dígito es (10 − suma % 10) % 10.
 */
class Clabe
{
    public const MENSAJE = 'La CLABE tiene que tener 18 dígitos y un dígito verificador que cuadre.';

    public static function normalizar(string $clabe): string
    {
        return preg_replace('/\s+/', '', trim($clabe)) ?? '';
    }

    public static function esValida(string $clabe): bool
    {
        $clabe = self::normalizar($clabe);

        if (! preg_match('/^\d{18}$/', $clabe)) {
            return false;
        }

        $pesos = [3, 7, 1];
        $suma = 0;

        for ($i = 0; $i < 17; $i++) {
            $suma += ((int) $clabe[$i] * $pesos[$i % 3]) % 10;
        }

        $esperado = (10 - ($suma % 10)) % 10;

        return (int) $clabe[17] === $esperado;
    }
}
