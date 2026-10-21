<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisarle a la persona que la distribuidora le emitió un vale.
 *
 * El dueño del vale es un cliente directo o un cliente mayorista (afiliación
 * de revendedor). Tener cuenta en la app es opcional: si clientes_directos o
 * revendedores no tienen usuario_id, no hay a quién avisar y no se hace nada
 * (el vale se emite igual).
 *
 * Igual que los demás avisos: la bandeja SIEMPRE queda; el push es un extra
 * que sale después del commit y, si Firebase falla, solo queda en el log.
 */
class NotificarValeEmitidoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Vale $vale): void
    {
        $vale->loadMissing(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        $usuarioId = $vale->clienteDirecto?->usuario_id
            ?? $vale->revendedorAfiliacion?->revendedor?->usuario_id;

        if ($usuarioId === null) {
            Log::info('Vale emitido a alguien sin cuenta en la app; no se notifica.', [
                'vale_id' => $vale->id,
            ]);

            return;
        }

        $usuarioId = (int) $usuarioId;
        $titulo = 'Recibiste un vale de $'.number_format((float) $vale->monto_original, 2);
        $mensaje = sprintf(
            'Tu distribuidora te emitió el vale %s por $%s. Vence el %s.',
            $vale->folio,
            number_format((float) $vale->monto_original, 2),
            $vale->fecha_vencimiento?->timezone('America/Mexico_City')->format('d/m/Y'),
        );

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

    private function mandarPush(int $usuarioId, string $titulo, string $mensaje, Vale $vale): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        DB::afterCommit(function () use ($tokens, $usuarioId, $titulo, $mensaje, $vale) {
            try {
                $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                    'tipo'         => 'vale_emitido',
                    'entidad_tipo' => 'vale',
                    'entidad_id'   => (string) $vale->id,
                ]);

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
        });
    }
}
