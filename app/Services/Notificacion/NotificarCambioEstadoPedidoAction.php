<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Services\Notificacion\Push\EnviadorPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificarCambioEstadoPedidoAction
{
    public function __construct(private EnviadorPush $enviadorPush)
    {
    }

    public function ejecutar(Pedido $pedido, string $estadoAnterior, string $estadoNuevo): void
    {
        if ($estadoAnterior === $estadoNuevo) {
            return;
        }

        $titulo = 'Pedido '.$pedido->folio.' → '.$estadoNuevo;
        $mensaje = sprintf(
            'El pedido %s cambió de «%s» a «%s».',
            $pedido->folio,
            $estadoAnterior,
            $estadoNuevo
        );

        $staffs = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->get(['usuario_id']);

        foreach ($staffs as $staff) {
            $this->crear(
                (int) $staff->usuario_id,
                (int) $pedido->distribuidora_id,
                'pedido_estado',
                $titulo,
                $mensaje,
                $pedido->id
            );
        }

        if (in_array($estadoNuevo, ['recibido_distribuidora', 'listo_entrega'], true)) {
            $this->notificarLlegadaAlDueno($pedido, $estadoNuevo);
        }
    }

    protected function notificarLlegadaAlDueno(Pedido $pedido, string $estadoNuevo): void
    {
        $pedido->loadMissing(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        $usuarioId = null;

        if ($pedido->tipo === 'cliente_directo') {
            $usuarioId = $pedido->clienteDirecto?->usuario_id;
        } else {
            $usuarioId = $pedido->revendedorAfiliacion?->revendedor?->usuario_id;
        }

        if (! $usuarioId) {
            return;
        }

        $titulo = 'Tu pedido '.$pedido->folio.' ya está en sucursal';
        $mensaje = $estadoNuevo === 'listo_entrega'
            ? 'Ya puedes pasar a recoger y liquidar el saldo pendiente.'
            : 'La mercancía de tu pedido llegó a la distribuidora. Te avisaremos cuando esté listo para entrega.';

        $this->crear(
            (int) $usuarioId,
            (int) $pedido->distribuidora_id,
            'pedido_llegada',
            $titulo,
            $mensaje,
            $pedido->id
        );

        // E16-01 (TG-135): además de la bandeja, aviso push a sus celulares.
        $this->mandarPush((int) $usuarioId, $titulo, $mensaje, $pedido);
    }

    /**
     * Push a los celulares del dueño del pedido (TG-135).
     *
     * Solo al dueño, no al personal: ellos trabajan en el panel web y ya
     * tienen su notificación en la bandeja.
     *
     * Dos reglas:
     *
     * 1. Sale DESPUÉS de que se confirme la transacción. El cambio a
     *    "recibido_distribuidora" ocurre dentro de una transacción (al marcar
     *    un ciclo como recibido): si el push saliera adentro y luego algo
     *    fallara, la transacción se desharía pero el aviso ya habría llegado
     *    al celular. Sin transacción abierta, afterCommit corre de inmediato.
     *
     * 2. Si Firebase falla (sin internet, sin llave, llave inválida) NO se cae
     *    nada: el pedido ya cambió de estado y la notificación de la bandeja
     *    ya existe. Solo se deja constancia en el log.
     */
    protected function mandarPush(int $usuarioId, string $titulo, string $mensaje, Pedido $pedido): void
    {
        $tokens = DispositivoFcm::where('usuario_id', $usuarioId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        DB::afterCommit(function () use ($tokens, $usuarioId, $titulo, $mensaje, $pedido) {
            try {
                $invalidos = $this->enviadorPush->enviar($tokens, $titulo, $mensaje, [
                    // Para que la app abra el pedido al tocar el aviso. Firebase
                    // solo acepta texto en estos datos.
                    'tipo'         => 'pedido_llegada',
                    'entidad_tipo' => 'pedido',
                    'entidad_id'   => (string) $pedido->id,
                ]);

                // Celulares donde ya no existe la app: se borran para no
                // seguir intentando.
                if ($invalidos !== []) {
                    DispositivoFcm::whereIn('token', $invalidos)->delete();
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo mandar el push de cambio de estado del pedido.', [
                    'pedido_id'  => $pedido->id,
                    'usuario_id' => $usuarioId,
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }

    protected function crear(
        int $usuarioId,
        int $distribuidoraId,
        string $tipo,
        string $titulo,
        string $mensaje,
        int $pedidoId
    ): void {
        Notificacion::create([
            'usuario_id'       => $usuarioId,
            'distribuidora_id' => $distribuidoraId,
            'tipo'             => $tipo,
            'titulo'           => $titulo,
            'mensaje'          => $mensaje,
            'leida_at'         => null,
            'entidad_tipo'     => 'pedido',
            'entidad_id'       => $pedidoId,
        ]);
    }
}