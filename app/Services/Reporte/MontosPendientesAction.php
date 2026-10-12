<?php

namespace App\Services\Reporte;

use App\Models\ClienteDirecto;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Vale;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TG-252 (R10, E14-03) — Cuánto falta por cobrar, por cliente directo y por
 * cliente mayorista (revendedor en el código), y los vales vigentes.
 *
 * Reglas (decididas por el PO):
 *   - Es una foto al día de hoy: sin filtro de fechas, solo ciclo opcional.
 *   - Cuentan los pedidos abiertos o entregados con saldo > 0; no cuentan los
 *     borradores ni los cerrados sin venta (mismos que TG-251).
 *   - Pagado y anticipo, con la regla de RegistrarPagoPedidoAction::resumen()
 *     (vía PagosDePedidos).
 *   - Vales vigentes: activos, con saldo y sin vencer.
 *
 * El tenant lo aplica BelongsToTenant en cada modelo.
 */
class MontosPendientesAction
{
    public function __construct(private PagosDePedidos $pagos)
    {
    }

    public function ejecutar(?int $cicloId = null): array
    {
        $pedidos = Pedido::query()
            ->whereNotIn('estado', array_merge(PedidosPorRevendedorAction::ESTADOS_EXCLUIDOS_EXTRA, RegistrarPagoPedidoAction::ESTADOS_CERRADOS))
            ->when($cicloId, fn (Builder $q) => $q->where('ciclo_compra_id', $cicloId))
            ->get(['id', 'tipo', 'cliente_directo_id', 'revendedor_distribuidora_id', 'total']);

        $ids = $pedidos->pluck('id')->all();
        $pagado = $this->pagos->pagadoPorPedido($ids);
        $anticipos = $this->pagos->anticipoRequeridoPorPedido($ids);

        // Solo los que todavía deben algo.
        $conSaldo = $pedidos
            ->map(function (Pedido $p) use ($pagado, $anticipos) {
                $total = round((float) $p->total, 2);
                $pagadoPedido = $pagado[$p->id] ?? 0.0;
                $requerido = $anticipos[$p->id] ?? 0.0;

                return [
                    'cliente_directo_id' => $p->cliente_directo_id ? (int) $p->cliente_directo_id : null,
                    'revendedor_distribuidora_id' => $p->revendedor_distribuidora_id ? (int) $p->revendedor_distribuidora_id : null,
                    'monto' => $total,
                    'pagado' => $pagadoPedido,
                    'pendiente' => round(max(0, $total - $pagadoPedido), 2),
                    'anticipo_pendiente' => round(max(0, $requerido - min($requerido, $pagadoPedido)), 2),
                ];
            })
            ->filter(fn ($p) => $p['pendiente'] > 0)
            ->values();

        $directos = $conSaldo->whereNotNull('cliente_directo_id');
        $mayoristas = $conSaldo->whereNull('cliente_directo_id')->whereNotNull('revendedor_distribuidora_id');

        $nombresDirectos = ClienteDirecto::query()
            ->whereIn('id', $directos->pluck('cliente_directo_id')->unique()->all())
            ->pluck('nombre', 'id');

        $nombresMayoristas = RevendedorDistribuidora::query()
            ->with('revendedor:id,nombre')
            ->whereIn('id', $mayoristas->pluck('revendedor_distribuidora_id')->unique()->all())
            ->get()
            ->mapWithKeys(fn ($a) => [$a->id => (string) ($a->revendedor?->nombre ?? 'Cliente mayorista #'.$a->id)]);

        return [
            'filtros' => ['ciclo_id' => $cicloId],
            'clientes_directos' => $this->agrupar($directos, 'cliente_directo_id', fn ($id) => (string) ($nombresDirectos[$id] ?? 'Cliente #'.$id), true),
            'revendedores' => $this->agrupar($mayoristas, 'revendedor_distribuidora_id', fn ($id) => $nombresMayoristas[$id] ?? 'Cliente mayorista #'.$id, false),
            'vales_vigentes' => $this->valesVigentes(),
        ];
    }

    private function agrupar(Collection $pedidos, string $clave, callable $nombre, bool $conAnticipo): array
    {
        $filas = $pedidos
            ->groupBy($clave)
            ->map(function (Collection $delCliente, $id) use ($clave, $nombre, $conAnticipo) {
                $fila = [
                    'id' => (int) $id,
                    'nombre' => $nombre((int) $id),
                    'pedidos' => $delCliente->count(),
                    'monto' => round((float) $delCliente->sum('monto'), 2),
                    'pagado' => round((float) $delCliente->sum('pagado'), 2),
                    'pendiente' => round((float) $delCliente->sum('pendiente'), 2),
                ];

                if ($conAnticipo) {
                    $fila['anticipo_pendiente'] = round((float) $delCliente->sum('anticipo_pendiente'), 2);
                }

                return $fila;
            })
            ->sortBy([['pendiente', 'desc'], ['nombre', 'asc']])
            ->values();

        $totales = [
            'clientes' => $filas->count(),
            'pedidos' => (int) $filas->sum('pedidos'),
            'monto' => round((float) $filas->sum('monto'), 2),
            'pagado' => round((float) $filas->sum('pagado'), 2),
            'pendiente' => round((float) $filas->sum('pendiente'), 2),
        ];

        if ($conAnticipo) {
            $totales['anticipo_pendiente'] = round((float) $filas->sum('anticipo_pendiente'), 2);
        }

        return ['filas' => $filas->all(), 'totales' => $totales];
    }

    private function valesVigentes(): array
    {
        $vales = Vale::query()
            ->with(['clienteDirecto:id,nombre', 'revendedorAfiliacion.revendedor:id,nombre'])
            ->where('estado', 'activo')
            ->where('saldo_actual', '>', 0)
            ->where('fecha_vencimiento', '>', now())
            ->orderBy('fecha_vencimiento')
            ->get();

        $filas = $vales->map(fn (Vale $v) => [
            'id' => (int) $v->id,
            'folio' => (string) $v->folio,
            'propietario' => $v->cliente_directo_id
                ? ['tipo' => 'cliente_directo', 'nombre' => (string) ($v->clienteDirecto?->nombre ?? '')]
                : ['tipo' => 'revendedor', 'nombre' => (string) ($v->revendedorAfiliacion?->revendedor?->nombre ?? '')],
            'saldo' => round((float) $v->saldo_actual, 2),
            'fecha_vencimiento' => $v->fecha_vencimiento?->toDateString(),
        ])->values();

        return [
            'filas' => $filas->all(),
            'totales' => ['vales' => $filas->count(), 'saldo' => round((float) $filas->sum('saldo'), 2)],
        ];
    }
}
