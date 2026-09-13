<?php

namespace App\Services\Pedido;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Services\CambiarEstadoPedidoService;
use Illuminate\Validation\ValidationException;

class MarcarListoEntregaAction
{
    public function __construct(
        protected CambiarEstadoPedidoService $cambiarEstado,
    ) {}

    public function ejecutar(Pedido $pedido): Pedido
    {
        $permitidos = ['recibido_distribuidora', 'listo_entrega'];

        if (! in_array($pedido->estado, $permitidos, true)) {
            throw ValidationException::withMessages([
                'pedido' => ['Solo se puede marcar listo para entrega cuando la mercancía ya está en sucursal.'],
            ]);
        }

        $ahora = now();
        $dias = (int) (ConfiguracionDistribuidora::query()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->value('dias_maximos_recoleccion') ?? 5);

        if ($pedido->fecha_listo_entrega === null) {
            $pedido->fecha_listo_entrega = $ahora;
        }

        $pedido->fecha_limite_recoleccion = $ahora->copy()->addDays($dias);
        $pedido->save();

        if ($pedido->estado !== 'listo_entrega') {
            $pedido = $this->cambiarEstado->cambiar(
                $pedido,
                'listo_entrega',
                null,
                'Marcado listo para entrega'
            );
        }

        return $pedido->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle', 'pagos']);
    }
}