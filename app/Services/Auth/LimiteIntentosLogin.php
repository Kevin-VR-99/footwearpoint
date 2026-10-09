<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * TG-185 (A2) — Limite de intentos de inicio de sesion.
 *
 * En el recorrido del equipo (29-sep) se escribio diez veces la contrasena
 * equivocada y el sistema nunca bloqueo; al final, con la buena, entro igual.
 * Asi, cualquiera puede probar contrasenas sin limite.
 *
 * La cuenta se lleva por CORREO + IP, no solo por IP: asi, varias personas
 * que comparten la red de la distribuidora no se bloquean entre ellas, y
 * quien ataca una cuenta desde una maquina si se bloquea.
 *
 * Esta clase vive en Services porque la usan las DOS puertas: el login del
 * panel web y el de la app (AuthController), que deben comportarse igual.
 */
class LimiteIntentosLogin
{
    /** Intentos fallidos permitidos antes de bloquear. */
    public const INTENTOS_MAXIMOS = 5;

    /** Cuanto hay que esperar despues de agotarlos, en segundos. */
    public const SEGUNDOS_DE_ESPERA = 60;

    /** True si esa combinacion ya agoto sus intentos y debe esperar. */
    public function bloqueado(?string $email, ?string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->llave($email, $ip), self::INTENTOS_MAXIMOS);
    }

    /** Segundos que faltan para poder intentar de nuevo (al menos 1). */
    public function segundosRestantes(?string $email, ?string $ip): int
    {
        return max(1, RateLimiter::availableIn($this->llave($email, $ip)));
    }

    /** Se llama cuando la contrasena no era la correcta. */
    public function registrarFallo(?string $email, ?string $ip): void
    {
        RateLimiter::hit($this->llave($email, $ip), self::SEGUNDOS_DE_ESPERA);
    }

    /** Al entrar bien se borra el contador: los fallos previos ya no cuentan. */
    public function limpiar(?string $email, ?string $ip): void
    {
        RateLimiter::clear($this->llave($email, $ip));
    }

    /** El aviso que ve la persona, en espanol y sin tecnicismos. */
    public function mensaje(int $segundos): string
    {
        return $segundos === 1
            ? 'Demasiados intentos fallidos. Espera 1 segundo e intenta de nuevo.'
            : "Demasiados intentos fallidos. Espera {$segundos} segundos e intenta de nuevo.";
    }

    /**
     * El correo se normaliza (minusculas y sin espacios) para que "A@b.test"
     * y "a@b.test " cuenten como la misma cuenta.
     */
    private function llave(?string $email, ?string $ip): string
    {
        return 'login|'.Str::lower(trim((string) $email)).'|'.(string) $ip;
    }
}
