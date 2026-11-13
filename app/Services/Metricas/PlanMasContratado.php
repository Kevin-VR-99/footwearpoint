<?php

namespace App\Services\Metricas;

use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;

/**
 * TG-394 (A34) — La regla de «plan más contratado», en un solo lugar.
 *
 * Nació en la pantalla de Planes (A23, S5-48) y Métricas la necesita igual.
 * Se saca aquí en vez de copiarla porque las tres decisiones que lleva dentro
 * son fáciles de perder al copiar, y entonces las dos pantallas dirían
 * números distintos sin que nadie sepa cuál creer:
 *
 * - Se cuentan DISTRIBUIDORAS DISTINTAS, no suscripciones. Una misma
 *   distribuidora deja varias filas con el tiempo (renovaciones, cambios de
 *   plan) y contarlas todas inflaría la cuenta.
 * - Solo las 'activa'. De los estados posibles (activa, prueba, cancelada,
 *   suspendida, vencida) se deja fuera 'prueba': una prueba todavía no dice
 *   que alguien haya elegido ese plan.
 * - Los empates se enseñan juntos, porque mostrar uno solo sería elegir al
 *   azar cuál.
 */
class PlanMasContratado
{
    /**
     * @return array{nombres: string, cuantas: int, planes: array<int>}
     */
    public function calcular(): array
    {
        $porPlan = Suscripcion::withoutGlobalScopes()
            ->where('estado', 'activa')
            ->groupBy('plan_id')
            ->selectRaw('plan_id, COUNT(DISTINCT distribuidora_id) as cuantas')
            ->pluck('cuantas', 'plan_id');

        $tope = (int) $porPlan->max();

        $empatados = $tope > 0
            ? $porPlan->filter(fn ($cuantas) => (int) $cuantas === $tope)->keys()->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'nombres' => PlanSuscripcion::whereIn('id', $empatados)->pluck('nombre')->sort()->values()->implode(' y '),
            'cuantas' => $tope,
            'planes' => $empatados,
        ];
    }
}
