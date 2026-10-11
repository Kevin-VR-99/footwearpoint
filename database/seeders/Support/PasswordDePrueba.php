<?php

namespace Database\Seeders\Support;

use RuntimeException;

/**
 * La contraseña de todas las cuentas de prueba (TG-186).
 *
 * Sale de SEED_PASSWORD. Si no está puesta, en local y en pruebas se usa
 * "password", que es cómodo para el equipo; en producción el seeder se
 * detiene, porque dejar cuentas con una contraseña conocida en un servidor de
 * verdad es abrirle la puerta a cualquiera.
 */
class PasswordDePrueba
{
    public const POR_OMISION = 'password';

    public static function obtener(): string
    {
        $configurada = (string) env('SEED_PASSWORD', '');

        if ($configurada !== '') {
            return $configurada;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'No se crean cuentas de prueba en producción sin SEED_PASSWORD. '
                .'Pon una contraseña en esa variable de entorno y vuelve a correr el seeder.'
            );
        }

        return self::POR_OMISION;
    }

    /** Qué decirle al equipo al final, sin enseñar la contraseña real. */
    public static function comoExplicarla(): string
    {
        return ((string) env('SEED_PASSWORD', '')) !== ''
            ? 'la contraseña de SEED_PASSWORD'
            : 'la contraseña "'.self::POR_OMISION.'"';
    }
}
