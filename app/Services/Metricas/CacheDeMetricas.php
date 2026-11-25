<?php

namespace App\Services\Metricas;

use App\Models\ActividadDiaria;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * TG-397 (A37) — Guardar unos minutos lo que ya se calculó en Métricas.
 *
 * POR QUÉ. Cada vez que el admin abre Métricas o cambia un filtro se corren
 * las mismas consultas agregadas sobre pagos, ventas y actividad. Los números
 * no cambian de un minuto a otro, así que se guardan SEGUNDOS segundos con
 * una llave que lleva el periodo y los filtros: la misma vista se sirve de lo
 * guardado y cualquier filtro distinto se calcula aparte.
 *
 * LO QUE NO SE NEGOCIA:
 *
 *   - La pantalla dice a qué hora se calcularon las cifras (hora de Ciudad de
 *     México) y tiene un botón para recalcular. Un número guardado que no
 *     dice que es guardado se lee como si fuera de este momento.
 *   - Un error NO se guarda. Si el cálculo truena, la excepción sigue su
 *     camino y la siguiente visita lo vuelve a intentar.
 *   - Si el almacén de caché falla (tabla cache caída, por ejemplo), se
 *     calcula directo y se reporta: la caché ahorra tiempo, no es una
 *     condición para ver la pantalla.
 *   - La llave lleva la fecha de inicio y fin del periodo. Al cambiar el día
 *     cambia la llave, así que nunca se sirve el periodo de ayer como de hoy.
 *
 * No cambia ninguna fórmula: guarda el resultado de IndicadoresDeMetricas y
 * SeriesDeMetricas tal cual lo devuelven.
 */
class CacheDeMetricas
{
    /** Cinco minutos: suficiente para no repetir consultas, poco para que estorbe. */
    public const SEGUNDOS = 300;

    /** Súbelo si cambia la forma de lo que se guarda, para no leer lo viejo. */
    public const VERSION = 'v1';

    /**
     * Lo guardado para esta vista o, si no hay, lo que devuelva $calcular.
     *
     * @return array{datos: array, calculado_at: string}
     */
    public function recordar(string $que, Carbon $desde, Carbon $hasta, ?int $distribuidoraId, ?int $planId, Closure $calcular): array
    {
        $llave = $this->llave($que, $desde, $hasta, $distribuidoraId, $planId);

        $guardado = $this->leer($llave);

        if (is_array($guardado) && is_array($guardado['datos'] ?? null) && is_string($guardado['calculado_at'] ?? null)) {
            return $guardado;
        }

        // Fuera del try a propósito: si el cálculo falla, que se entere quien
        // lo pidió (Métricas enseña el error con su botón de reintentar).
        $nuevo = [
            'datos' => $calcular(),
            'calculado_at' => now(ActividadDiaria::ZONA)->toIso8601String(),
        ];

        $this->guardar($llave, $nuevo);

        return $nuevo;
    }

    /**
     * Borra lo guardado de esta vista, para el botón «Actualizar ahora».
     *
     * @param  list<string>  $que
     */
    public function olvidar(array $que, Carbon $desde, Carbon $hasta, ?int $distribuidoraId, ?int $planId): void
    {
        foreach ($que as $parte) {
            try {
                Cache::forget($this->llave($parte, $desde, $hasta, $distribuidoraId, $planId));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function llave(string $que, Carbon $desde, Carbon $hasta, ?int $distribuidoraId, ?int $planId): string
    {
        return implode(':', [
            'metricas',
            self::VERSION,
            $que,
            $desde->toDateString(),
            $hasta->toDateString(),
            'd'.($distribuidoraId ?? 'todas'),
            'p'.($planId ?? 'todos'),
        ]);
    }

    private function leer(string $llave): mixed
    {
        try {
            return Cache::get($llave);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function guardar(string $llave, array $valor): void
    {
        try {
            Cache::put($llave, $valor, self::SEGUNDOS);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
