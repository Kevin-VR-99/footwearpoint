<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use Illuminate\Support\Collection;

/**
 * Qué pedidos no pueden irse a fábrica porque les falta el anticipo (TG-215).
 *
 * Un pedido de cliente directo entra al pedido a fábrica solo cuando su
 * anticipo está cubierto por completo: pagado a medias cuenta como no pagado.
 * El anticipo se puede cubrir con dinero o con un vale, y eso ya lo sabe el
 * resumen de pagos del pedido, así que aquí no se recalcula nada.
 *
 * Los pedidos de revendedor nunca piden anticipo, así que nunca se quedan
 * fuera. Si una distribuidora configura su anticipo en $0, tampoco.
 *
 * Es el único lugar donde se decide esto: lo usan la solicitud a fábrica y el
 * aviso que ve el personal antes de solicitarla, para que nunca digan cosas
 * distintas.
 */
class AnticipoPendientePedido
{
    public function __construct(private RegistrarPagoPedidoAction $pagos) {}

    public function cuantoFalta(Pedido $pedido): float
    {
        return (float) $this->pagos->resumen($pedido)['anticipo_pendiente'];
    }

    public function seQuedaFuera(Pedido $pedido): bool
    {
        return $this->cuantoFalta($pedido) > 0;
    }

    /**
     * De una lista de pedidos, los que se quedan fuera, con lo que les falta.
     *
     * @param  iterable<Pedido>  $pedidos
     * @return Collection<int, array{pedido: Pedido, falta: float}>
     */
    public function losQueSeQuedanFuera(iterable $pedidos): Collection
    {
        return collect($pedidos)
            ->map(fn (Pedido $pedido) => ['pedido' => $pedido, 'falta' => $this->cuantoFalta($pedido)])
            ->filter(fn (array $fila) => $fila['falta'] > 0)
            ->values();
    }
}
