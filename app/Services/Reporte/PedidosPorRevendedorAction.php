<?php

namespace App\Services\Reporte;

use App\Models\Pago;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\ValeMovimiento;
use App\Services\Pedido\RegistrarPagoPedidoAction;

/**
 * TG-251 (R9, E14-05) — Total de pedidos y montos por cliente mayorista
 * (revendedor en el código), para saber quiénes compran más.
 *
 * Reglas (decididas por el PO):
 *   - Cuenta desde que el pedido se coloca: sin borradores ni pedidos cerrados
 *     sin venta (rechazado, descartado, no surtido, vencido en recolección).
 *   - El filtro de fechas usa la fecha de colocación (días completos).
 *   - Por cliente: monto (total de sus pedidos), pagado (pagos de entrada
 *     aplicados + vales aplicados, como RegistrarPagoPedidoAction::resumen)
 *     y pendiente (lo que falta de cada pedido, nunca negativo).
 *
 * El tenant lo aplica BelongsToTenant en cada modelo.
 */
class PedidosPorRevendedorAction
{
    /** Estados que no cuentan además de los cerrados. */
    public const ESTADOS_EXCLUIDOS_EXTRA = ['borrador'];

    /**
     * @return array{
     *     filtros: array{desde: ?string, hasta: ?string, ciclo_id: ?int},
     *     filas: list<array{revendedor_distribuidora_id: int, nombre: string, pedidos: int, monto: float, pagado: float, pendiente: float}>,
     *     totales: array{clientes: int, pedidos: int, monto: float, pagado: float, pendiente: float}
     * }
     */
    public function ejecutar(?string $desde = null, ?string $hasta = null, ?int $cicloId = null): array
    {
        $consulta = Pedido::query()
            ->whereNotNull('revendedor_distribuidora_id')
            ->whereNotNull('fecha_colocacion')
            ->whereNotIn('estado', array_merge(self::ESTADOS_EXCLUIDOS_EXTRA, RegistrarPagoPedidoAction::ESTADOS_CERRADOS));

        if ($desde) {
            $consulta->whereDate('fecha_colocacion', '>=', $desde);
        }
        if ($hasta) {
            $consulta->whereDate('fecha_colocacion', '<=', $hasta);
        }
        if ($cicloId) {
            $consulta->where('ciclo_compra_id', $cicloId);
        }

        $pedidos = $consulta->get(['id', 'revendedor_distribuidora_id', 'total']);
        $ids = $pedidos->pluck('id')->all();

        $pagos = $ids === [] ? collect() : Pago::query()
            ->whereIn('pedido_id', $ids)
            ->where('estado', 'aplicado')
            ->where('direccion', 'entrada')
            ->selectRaw('pedido_id, SUM(monto) as suma')
            ->groupBy('pedido_id')
            ->pluck('suma', 'pedido_id');

        $vales = $ids === [] ? collect() : ValeMovimiento::query()
            ->whereIn('pedido_id', $ids)
            ->where('tipo', 'aplicacion')
            ->selectRaw('pedido_id, SUM(monto) as suma')
            ->groupBy('pedido_id')
            ->pluck('suma', 'pedido_id');

        $nombres = RevendedorDistribuidora::query()
            ->with('revendedor:id,nombre')
            ->whereIn('id', $pedidos->pluck('revendedor_distribuidora_id')->unique()->all())
            ->get()
            ->mapWithKeys(fn ($a) => [$a->id => (string) ($a->revendedor?->nombre ?? 'Cliente mayorista #'.$a->id)]);

        $filas = $pedidos
            ->groupBy('revendedor_distribuidora_id')
            ->map(function ($delCliente, $afiliacionId) use ($pagos, $vales, $nombres) {
                $monto = 0.0;
                $pagado = 0.0;
                $pendiente = 0.0;

                foreach ($delCliente as $pedido) {
                    $total = round((float) $pedido->total, 2);
                    $pagadoPedido = round((float) ($pagos[$pedido->id] ?? 0) + (float) ($vales[$pedido->id] ?? 0), 2);

                    $monto += $total;
                    $pagado += $pagadoPedido;
                    $pendiente += max(0, $total - $pagadoPedido);
                }

                return [
                    'revendedor_distribuidora_id' => (int) $afiliacionId,
                    'nombre' => $nombres[$afiliacionId] ?? 'Cliente mayorista #'.$afiliacionId,
                    'pedidos' => $delCliente->count(),
                    'monto' => round($monto, 2),
                    'pagado' => round($pagado, 2),
                    'pendiente' => round($pendiente, 2),
                ];
            })
            ->sortBy([['monto', 'desc'], ['nombre', 'asc']])
            ->values();

        return [
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'ciclo_id' => $cicloId],
            'filas' => $filas->all(),
            'totales' => [
                'clientes' => $filas->count(),
                'pedidos' => (int) $filas->sum('pedidos'),
                'monto' => round((float) $filas->sum('monto'), 2),
                'pagado' => round((float) $filas->sum('pagado'), 2),
                'pendiente' => round((float) $filas->sum('pendiente'), 2),
            ],
        ];
    }
}
