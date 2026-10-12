<?php

namespace App\Services\Distribuidora;

use Illuminate\Support\Facades\RateLimiter;

/**
 * TG-220 (E2-08) — Cuántas solicitudes de alta acepta la misma IP.
 *
 * Es una de las tres defensas contra envíos automáticos, junto con el campo
 * trampa y el tiempo mínimo de llenado. Sin servicios de fuera.
 *
 * Se cuenta por IP porque el formulario es público: no hay cuenta todavía.
 * En Railway la IP es la de verdad y no la del proxy, porque bootstrap/app.php
 * ya hace trustProxies(at: '*').
 *
 * El tope es configurable (FOOTWEARPOINT_SOLICITUDES_POR_HORA, 3 por omisión)
 * porque en la demostración varias personas prueban desde la misma red.
 */
class LimiteSolicitudesAlta
{
    private const VENTANA_SEGUNDOS = 3600;

    /** Ventana del segundo límite, el de los intentos. */
    private const VENTANA_INTENTOS_SEGUNDOS = 600;

    public function tope(): int
    {
        return max(1, (int) config('footwearpoint.solicitudes_alta.por_hora_por_ip', 3));
    }

    /**
     * Tope del segundo límite: intentos de envío, se guarden o no.
     *
     * El primer límite solo cuenta las solicitudes que sí se guardaron, así
     * que un intento que falla la validación no gasta nada. Como el formulario
     * dice "Ese correo ya tiene una cuenta en FootwearPoint", sin este
     * segundo límite se podrían probar correos sin tope para averiguar quién
     * está registrado. Por eso va más holgado: estorba a quien raspa, no a
     * quien se equivoca al llenar.
     */
    public function topeDeIntentos(): int
    {
        return max(1, (int) config('footwearpoint.solicitudes_alta.intentos_por_ip', 10));
    }

    public function bloqueado(?string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->llave($ip), $this->tope())
            || RateLimiter::tooManyAttempts($this->llaveDeIntentos($ip), $this->topeDeIntentos());
    }

    /** Se llama cuando una solicitud SÍ se guardó. */
    public function registrar(?string $ip): void
    {
        RateLimiter::hit($this->llave($ip), self::VENTANA_SEGUNDOS);
    }

    /** Se llama en CADA envío, se guarde o no. */
    public function registrarIntento(?string $ip): void
    {
        RateLimiter::hit($this->llaveDeIntentos($ip), self::VENTANA_INTENTOS_SEGUNDOS);
    }

    /**
     * Minutos que faltan para poder intentar otra vez (al menos 1). Se toma
     * la espera más larga de los dos límites.
     */
    public function minutosRestantes(?string $ip): int
    {
        $segundos = max(
            RateLimiter::tooManyAttempts($this->llave($ip), $this->tope())
                ? RateLimiter::availableIn($this->llave($ip))
                : 0,
            RateLimiter::tooManyAttempts($this->llaveDeIntentos($ip), $this->topeDeIntentos())
                ? RateLimiter::availableIn($this->llaveDeIntentos($ip))
                : 0,
        );

        return max(1, (int) ceil($segundos / 60));
    }

    /** En español y sin tecnicismos; tampoco dice cuántas van. */
    public function mensaje(int $minutos): string
    {
        return $minutos === 1
            ? 'Ya recibimos varias solicitudes desde aquí. Intenta de nuevo en 1 minuto.'
            : "Ya recibimos varias solicitudes desde aquí. Intenta de nuevo en {$minutos} minutos.";
    }

    private function llave(?string $ip): string
    {
        return 'solicitud-alta|'.(string) $ip;
    }

    private function llaveDeIntentos(?string $ip): string
    {
        return 'solicitud-alta-intento|'.(string) $ip;
    }
}
