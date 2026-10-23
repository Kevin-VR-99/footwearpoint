<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rechaza contraseñas fáciles de adivinar sin depender de la red: las más
 * comunes, secuencias (12345678, abcdefgh, qwerty123), un solo carácter
 * repetido (11111111) y las que contienen el nombre de la app o el correo /
 * nombre de la persona. Las frases largas (16+ caracteres) solo se revisan
 * contra los datos personales, para permitir passphrases.
 */
class PasswordSegura implements ValidationRule, DataAwareRule
{
    public const MENSAJE_COMUN = 'Esa contraseña es demasiado común o fácil de adivinar. Elige otra.';
    public const MENSAJE_SECUENCIA = 'La contraseña no puede ser una secuencia o un carácter repetido (como 12345678, abcdefgh o 11111111).';
    public const MENSAJE_PERSONAL = 'La contraseña no puede contener tu correo, tu nombre ni el nombre de la aplicación.';

    /** Lista offline (minúsculas, sin espacios). */
    private const COMUNES = [
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'p4ssw0rd',
        'contraseña', 'contrasena', 'contrasena1', 'contrasena123', 'contraseña123',
        'qwerty', 'qwerty1', 'qwerty12', 'qwerty123', 'qwerty1234', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm',
        'qazwsx', 'qazwsxedc', '1qaz2wsx', '1q2w3e4r', '1q2w3e4r5t', 'q1w2e3r4', 'a1b2c3d4',
        'abc12345', 'abcd1234', 'abc123456', '123abc', 'abc123', 'iloveyou', 'iloveyou1', 'teamo123',
        'admin', 'admin123', 'admin1234', 'administrador', 'administrador1', 'root1234', 'welcome1', 'welcome123',
        'letmein', 'letmein1', 'monkey123', 'dragon123', 'football1', 'baseball1', 'sunshine1', 'princess1',
        'master123', 'superman1', 'batman123', 'trustno1', 'starwars1', 'shadow123', 'michael1', 'jordan23',
        'changeme', 'changeme1', 'secret123', 'default1', 'test1234', 'prueba123', 'usuario1', 'usuario123',
        'mexico123', 'mexico2024', 'mexico2025', 'mexico2026', 'hola1234', 'holamundo1', 'zapatos1', 'zapatos123',
        'footwear', 'footwearpoint', 'footwearpoint1', 'footwearpoint123',
    ];

    private const FILAS = ['abcdefghijklmnopqrstuvwxyz', '01234567890', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm'];

    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $normal = Str::lower(preg_replace('/\s+/u', '', $value));

        if ($this->contieneDatosPersonales($normal)) {
            $fail(self::MENSAJE_PERSONAL);

            return;
        }

        if (mb_strlen($value) >= 16) {
            return; // frase larga: suficiente entropía
        }

        if (in_array($normal, self::COMUNES, true) || in_array(rtrim($normal, '!.#$*@'), self::COMUNES, true)) {
            $fail(self::MENSAJE_COMUN);

            return;
        }

        if ($this->esSecuenciaORepeticion($normal)) {
            $fail(self::MENSAJE_SECUENCIA);
        }
    }

    private function esSecuenciaORepeticion(string $p): bool
    {
        // Un solo carácter o un patrón corto repetido: 11111111, abababab, 12121212.
        if (preg_match('/^(.{1,3})\1+.{0,2}$/u', $p)) {
            return true;
        }

        // Se arma solo con tramos de secuencias (ascendentes o descendentes):
        // 12345678, 87654321, abcdefgh, qwerty123, asdf1234.
        $resto = $p;
        $tramos = [];
        foreach (self::FILAS as $fila) {
            $tramos[] = $fila;
            $tramos[] = strrev($fila);
        }
        while ($resto !== '') {
            $mejor = 0;
            foreach ($tramos as $fila) {
                for ($largo = mb_strlen($resto); $largo >= 3; $largo--) {
                    if (str_contains($fila, mb_substr($resto, 0, $largo))) {
                        $mejor = max($mejor, $largo);
                        break;
                    }
                }
            }
            if ($mejor === 0) {
                return false;
            }
            $resto = mb_substr($resto, $mejor);
        }

        return true;
    }

    private function contieneDatosPersonales(string $p): bool
    {
        $candidatos = [];
        $usuario = auth()->user();
        if ($usuario) {
            $candidatos[] = (string) ($usuario->email ?? '');
            $candidatos[] = (string) ($usuario->nombre ?? $usuario->name ?? '');
        }
        foreach ($this->data as $clave => $valor) {
            if (is_string($valor) && is_string($clave)
                && preg_match('/(email|correo|nombre|name)/i', $clave)
                && ! str_contains(Str::lower($clave), 'password')) {
                $candidatos[] = $valor;
            }
        }

        foreach ($candidatos as $c) {
            $c = Str::lower(trim($c));
            if ($c === '') {
                continue;
            }
            $piezas = [preg_replace('/\s+/u', '', $c)];
            if (str_contains($c, '@')) {
                $piezas[] = Str::before($c, '@');
            }
            foreach (preg_split('/[\s@._\-]+/u', $c) as $p2) {
                $piezas[] = $p2;
            }
            foreach ($piezas as $pieza) {
                if (mb_strlen($pieza) >= 4 && ! in_array($pieza, ['gmail', 'hotmail', 'outlook', 'yahoo', 'com', 'mx'], true)
                    && str_contains($p, $pieza)) {
                    return true;
                }
            }
        }

        return false;
    }
}
