<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-281 — Avisarle a la persona que le emitieron un vale.
 *
 * Faltaba: emitir un vale desde el panel no avisaba nada. El vale aparecía
 * en la app solo si a la persona se le ocurría entrar a mirar, y nadie entra
 * a mirar algo que no sabe que existe. El único aviso de vales que había era
 * el de «está por vencer» (TG-242), que llega cuando ya casi es tarde.
 *
 * Mismo criterio que el resto de avisos de la casa: el aviso queda SIEMPRE
 * en su bandeja de la app, y el push es un extra. Si Firebase falla, el
 * aviso ya existe y lo ve al entrar; solo queda constancia en el log.
 */
class NotificarValeEmitidoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Vale $vale): void
    {
        $usuarioId = $this->cuentaDe($vale);

        // Un vale puede ser de alguien que todavía no tiene cuenta en la app
        // (el panel los registra como contacto antes de darles acceso). Ahí
        // no hay a quién avisarle, y no es un error: el vale existe igual y
        // se lo cobran en el mostrador.
        if ($usuarioId === null) {
            return;
        }

        $titulo = 'Te emitieron un vale';
        $mensaje = $this->mensaje($vale);

        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => (int) $vale->distribuidora_id,
            'tipo'             => 'vale_emitido',
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'vale',
            'entidad_id'       => $vale->id,
        ]);

        $this->mandarPush($usuarioId, $titulo, $mensaje, $vale);
    }

    /**
     * Lo que la persona necesita saber: cuánto, con qué folio y hasta cuándo
     * puede usarlo. El motivo va si lo hay, porque casi siempre explica de
     * dónde salió (una devolución, un pedido no surtido).
     */
    private function mensaje(Vale $vale): string
    {
        $texto = sprintf(
            'Tienes un vale de $%s (%s) para usar en tus compras.',
            number_format((float) $vale->saldo_actual, 2),
            $vale->folio,
        );

        if ($vale->fecha_vencimiento !== null) {
            $texto .= ' Vence el '.$vale->fecha_vencimiento->format('d/m/Y').'.';
        }

        $motivo = trim((string) $vale->motivo);

        if ($motivo !== '') {
            $texto .= ' Motivo: '.$motivo.'.';
        }

        return $texto;
    }

    /** El vale es de un cliente directo o de un cliente mayorista, nunca de los dos. */
    private function cuentaDe(Vale $vale): ?int
    {
        $usuarioId = $vale->clienteDirecto?->usuario_id
            ?? $vale->revendedorAfiliacion?->revendedor?->usuario_id;

        return $usuarioId !== null ? (int) $usuarioId : null;
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
                'tipo'         => 'vale_emitido',
                'entidad_tipo' => 'vale',
                'entidad_id'   => (string) $vale->id,
            ]);

            // Celulares donde ya no existe la app.
            if ($invalidos !== []) {
                DispositivoFcm::whereIn('token', $invalidos)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el push de vale emitido.', [
                'vale_id'    => $vale->id,
                'usuario_id' => $usuarioId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
