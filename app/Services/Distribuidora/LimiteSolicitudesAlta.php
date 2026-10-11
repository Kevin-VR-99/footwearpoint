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

    public function tope(): int
    {
        return max(1, (int) config('footwearpoint.solicitudes_alta.por_hora_por_ip', 3));
    }

    public function bloqueado(?string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->llave($ip), $this->tope());
    }

    /** Se llama cuando una solicitud SÍ se guardó. */
    public function registrar(?string $ip): void
    {
        RateLimiter::hit($this->llave($ip), self::VENTANA_SEGUNDOS);
    }

    /** Minutos que faltan para poder intentar otra vez (al menos 1). */
    public function minutosRestantes(?string $ip): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn($this->llave($ip)) / 60));
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
}
