<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-226 (G7) — Avisa que se confirmó un pago con Mercado Pago.
 *
 * Al dueño del pedido: bandeja de la app + push. Al personal activo de la
 * distribuidora: solo bandeja (trabajan en el panel). Mismo criterio que
 * NotificarCambioEstadoPedidoAction.
 *
 * Se envuelve en Tenant::forzar porque el aviso de Mercado Pago (G9) llega
 * sin sesión: así las consultas usan el scope normal de la distribuidora del
 * pago, sin quitar scopes.
 */
class NotificarPagoMercadoPagoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Pago $pago, Pedido $pedido): void
    {
        Tenant::forzar((int) $pago->distribuidora_id, function () use ($pago, $pedido) {
            $monto = '$'.number_format((float) $pago->monto, 2);
            $tipo = $pago->tipo === 'anticipo' ? 'anticipo' : 'pago';

            $pedido->loadMissing('clienteDirecto');
            $usuarioId = $pedido->tipo === 'cliente_directo' ? $pedido->clienteDirecto?->usuario_id : null;

            if ($usuarioId) {
                $titulo = "Recibimos tu {$tipo} del pedido {$pedido->folio}";
                $mensaje = "Mercado Pago confirmó tu pago de {$monto}. ¡Gracias!";

                $this->crear((int) $usuarioId, $pago, $pedido, $titulo, $mensaje);
                $this->mandarPush((int) $usuarioId, $titulo, $mensaje, $pedido);
            }

            $titulo = "Pago con Mercado Pago del pedido {$pedido->folio}";
            $mensaje = "Mercado Pago confirmó un {$tipo} de {$monto} (folio {$pago->folio}).";

            DistribuidoraStaff::query()
                ->where('estado', 'activo')
                ->whereNotNull('usuario_id')
                ->pluck('usuario_id')
                ->each(fn ($staffUsuarioId) => $this->crear((int) $staffUsuarioId, $pago, $pedido, $titulo, $mensaje));
        });
    }

    private function crear(int $usuarioId, Pago $pago, Pedido $pedido, string $titulo, string $mensaje): void
    {
        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => $pago->distribuidora_id,
            'tipo'             => 'pago_mercado_pago',
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => $pedido->id,
        ]);
    }

    /** Push al celular del dueño, después del commit y sin tumbar nada si Firebase falla. */
    private function mandarPush(int $usuarioId, string $titulo, string $mensaje, Pedido $pedido): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        DB::afterCommit(function () use ($tokens, $usuarioId, $titulo, $mensaje, $pedido) {
            try {
                $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                    'tipo'         => 'pago_mercado_pago',
                    'entidad_tipo' => 'pedido',
                    'entidad_id'   => (string) $pedido->id,
                ]);

                if ($invalidos !== []) {
                    DispositivoFcm::whereIn('token', $invalidos)->delete();
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo mandar el push del pago con Mercado Pago.', [
                    'pedido_id'  => $pedido->id,
                    'usuario_id' => $usuarioId,
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }
}
