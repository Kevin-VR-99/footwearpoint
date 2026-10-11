<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-242 (A13 / E16-02) — Avisarle a la persona que su vale está por vencer.
 *
 * El aviso queda SIEMPRE en su bandeja de la app; el push es un extra. Si
 * Firebase falla, el aviso de la bandeja ya existe y la persona lo ve al
 * entrar, así que solo queda constancia en el log.
 */
class NotificarValePorVencerAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Vale $vale, int $usuarioId, int $diasQueFaltan): void
    {
        $titulo = 'Tu vale '.$vale->folio.' está por vencer';
        $mensaje = $this->mensaje($vale, $diasQueFaltan);

        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => (int) $vale->distribuidora_id,
            'tipo'             => 'vale_por_vencer',
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'vale',
            'entidad_id'       => $vale->id,
        ]);

        $this->mandarPush($usuarioId, $titulo, $mensaje, $vale);
    }

    /**
     * Lo primero que la persona necesita saber es cuánto le queda y hasta
     * cuándo. "En 1 día" y "hoy" se dicen distinto a propósito: un aviso que
     * dice "en 0 días" se lee como un error del sistema.
     */
    private function mensaje(Vale $vale, int $dias): string
    {
        $cuando = match (true) {
            $dias <= 0 => 'vence hoy',
            $dias === 1 => 'vence mañana',
            default => 'vence en '.$dias.' días',
        };

        return sprintf(
            'Tu vale %s %s (%s) y todavía tiene $%s de saldo. Úsalo antes de que se venza.',
            $vale->folio,
            $cuando,
            $vale->fecha_vencimiento?->format('d/m/Y'),
            number_format((float) $vale->saldo_actual, 2),
        );
    }

    private function mandarPush(int $usuarioId, string $titulo, string $mensaje, Vale $vale): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        try {
            $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                // Para que la app abra sus vales al tocar el aviso.
                'tipo'         => 'vale_por_vencer',
                'entidad_tipo' => 'vale',
                'entidad_id'   => (string) $vale->id,
            ]);

            // Celulares donde ya no existe la app.
            if ($invalidos !== []) {
                DispositivoFcm::whereIn('token', $invalidos)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el push de vale por vencer.', [
                'vale_id'    => $vale->id,
                'usuario_id' => $usuarioId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
