<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Services\CambiarEstadoPedidoService;
use App\Support\PropietarioActual;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los dos últimos pasos de un pedido en el mostrador (TG-169, corrige E9-05 /
 * E16-01): "listo para entrega" y "entregado".
 *
 * Antes nada pasaba un pedido a esos estados: la entrega no se podía
 * confirmar, el push de "listo para entrega" nunca salía y ningún ciclo con
 * pedidos reales se podía finalizar.
 *
 * Solo el personal. El cambio de estado va por CambiarEstadoPedidoService,
 * que guarda el historial y manda el aviso al dueño.
 */
class EntregaPedidoAction
{
    public function __construct(
        protected CambiarEstadoPedidoService $cambiarEstado,
        protected RegistrarPagoPedidoAction $pagos,
    ) {}

    /**
     * La mercancía ya llegó y se apartó para el cliente. Desde aquí corre el
     * plazo para recogerla.
     */
    public function marcarListo(Pedido $pedido): Pedido
    {
        return $this->enTransaccion($pedido, function (Pedido $pedido) {
            if ($pedido->estado !== 'recibido_distribuidora') {
                throw ValidationException::withMessages([
                    'pedido' => ['Solo un pedido recibido en la distribuidora se puede marcar listo para entrega.'],
                ]);
            }

            $dias = (int) (ConfiguracionDistribuidora::query()->value('dias_maximos_recoleccion') ?? 0);

            $pedido->fecha_listo_entrega = now();
            $pedido->fecha_limite_recoleccion = $dias > 0 ? now()->addDays($dias) : null;

            return $this->cambiarEstado->cambiar($pedido, 'listo_entrega', null, 'Listo para entrega');
        });
    }

    /**
     * El cliente se llevó su pedido. Se puede entregar directo al recibirlo
     * (si el cliente está ahí), pero siempre con el saldo pagado.
     */
    public function marcarEntregado(Pedido $pedido): Pedido
    {
        return $this->enTransaccion($pedido, function (Pedido $pedido) {
            if (! in_array($pedido->estado, ['recibido_distribuidora', 'listo_entrega'], true)) {
                throw ValidationException::withMessages([
                    'pedido' => ['Solo se puede entregar un pedido que ya llegó a la distribuidora.'],
                ]);
            }

            $saldo = $this->pagos->resumen($pedido)['saldo'];
            if ($saldo > 0) {
                throw ValidationException::withMessages([
                    'pedido' => ['Cobra el saldo pendiente ($'.number_format($saldo, 2).') antes de entregar.'],
                ]);
            }

            $pedido->fecha_entrega = now();

            return $this->cambiarEstado->cambiar($pedido, 'entregado', null, 'Entregado al cliente');
        });
    }

    /** Relee el pedido bloqueado, para que dos clics seguidos no lo muevan dos veces. */
    protected function enTransaccion(Pedido $pedido, callable $paso): Pedido
    {
        abort_unless(PropietarioActual::esDeLaCasa(), 403, 'Solo el personal de la distribuidora puede entregar pedidos.');

        return DB::transaction(function () use ($pedido, $paso) {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedido->id);

            return $paso($pedido);
        });
    }
}
