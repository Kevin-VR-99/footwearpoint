<?php

namespace App\Support;

/**
 * Teléfono de un cliente directo: solo dígitos y los últimos 10.
 * +52, espacios y guiones se quitan. Si no quedan 10 dígitos, es null.
 * Null no choca con el índice único por distribuidora.
 */
class TelefonoCliente
{
    public const MENSAJE_DIGITOS = 'Escribe un teléfono de 10 dígitos.';

    public const MENSAJE_REPETIDO = 'Ese teléfono ya está registrado en esta distribuidora.';

    public static function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        if ($digitos === '') {
            return null;
        }

        $ultimos = strlen($digitos) > 10 ? substr($digitos, -10) : $digitos;

        return strlen($ultimos) === 10 ? $ultimos : null;
    }
}
