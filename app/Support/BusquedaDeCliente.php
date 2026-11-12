<?php

namespace App\Support;

/**
 * Coincide un cliente por nombre, o por teléfono ya normalizado a 10 dígitos.
 */
class BusquedaDeCliente
{
    public static function coincide(?string $nombre, ?string $telefono, string $termino): bool
    {
        $termino = trim($termino);

        if ($termino === '') {
            return true;
        }

        if (str_contains(mb_strtolower((string) $nombre), mb_strtolower($termino))) {
            return true;
        }

        $buscado = TelefonoCliente::normalizar($termino);
        $guardado = TelefonoCliente::normalizar($telefono);

        return $buscado !== null && $guardado !== null && $buscado === $guardado;
    }
}
