<?php

namespace App\Services\Vale;

use App\Models\Vale;
use App\Services\Notificacion\NotificarValePorVencerAction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-242 (A13 / E16-02) — Buscar los vales que están por vencer y avisar.
 *
 * Lo corre una vez al día el comando vales:avisar-por-vencer.
 *
 * Tres decisiones que vale la pena explicar:
 *
 *  - Se avisa UNA sola vez por vale. Para eso existe la columna
 *    vales.aviso_vencimiento_enviado_at: se marca al avisar, así que aunque
 *    el comando corra dos veces el mismo día nadie recibe el aviso repetido.
 *
 *  - Mira TODAS las distribuidoras, sin el filtro de siempre: el comando no
 *    corre dentro de ninguna. Cada aviso sí se guarda con la distribuidora
 *    del vale, así que nadie ve nada de otra.
 *
 *  - A quien no tiene cuenta en la app no se le marca nada. Tener cuenta es
 *    opcional y a esas personas las llama la distribuidora; si le dan cuenta
 *    antes de que el vale venza, el aviso le llegará igual.
 */
class AvisarValesPorVencerAction
{
    public function __construct(private NotificarValePorVencerAction $notificar)
    {
    }

    /**
     * @return array{revisados: int, avisados: int, sin_cuenta: int}
     */
    public function ejecutar(?int $dias = null): array
    {
        $dias = $dias ?? (int) config('vales.dias_aviso_vencimiento');
        $limite = now()->addDays(max(0, $dias))->endOfDay();

        $vales = Vale::withoutGlobalScopes()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->where('estado', 'activo')
            // Sin saldo no hay nada que apurarse en usar.
            ->where('saldo_actual', '>', 0)
            ->whereNull('aviso_vencimiento_enviado_at')
            ->whereNotNull('fecha_vencimiento')
            // Ya vencido no se avisa: el aviso llegaría tarde y solo confunde.
            ->where('fecha_vencimiento', '>=', now())
            ->where('fecha_vencimiento', '<=', $limite)
            ->orderBy('fecha_vencimiento')
            ->get();

        $avisados = 0;
        $sinCuenta = 0;

        foreach ($vales as $vale) {
            $usuarioId = $this->cuentaDe($vale);

            if ($usuarioId === null) {
                $sinCuenta++;

                continue;
            }

            try {
                $this->notificar->ejecutar($vale, $usuarioId, $this->diasQueFaltan($vale));

                // Solo después de avisar: si algo truena, mañana se reintenta.
                $vale->forceFill(['aviso_vencimiento_enviado_at' => now()])->save();

                $avisados++;
            } catch (Throwable $e) {
                // Un vale que falla no detiene a los demás.
                Log::error('No se pudo avisar que un vale está por vencer.', [
                    'vale_id' => $vale->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return [
            'revisados'  => $vales->count(),
            'avisados'   => $avisados,
            'sin_cuenta' => $sinCuenta,
        ];
    }

    /** El vale es de un cliente directo o de un cliente mayorista, nunca de los dos. */
    private function cuentaDe(Vale $vale): ?int
    {
        $usuarioId = $vale->clienteDirecto?->usuario_id
            ?? $vale->revendedorAfiliacion?->revendedor?->usuario_id;

        return $usuarioId !== null ? (int) $usuarioId : null;
    }

    /** Días completos que faltan. Hoy es 0, no un número negativo. */
    private function diasQueFaltan(Vale $vale): int
    {
        return max(0, (int) now()->startOfDay()->diffInDays($vale->fecha_vencimiento->startOfDay(), false));
    }
}
