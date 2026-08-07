<?php

namespace App\Services\Reporte;

use App\Models\Pedido;
use App\Models\Vale;
use App\Models\VentaDirecta;
use Illuminate\Support\Facades\Schema;

class ResumenOperativoAction
{
    public function ejecutar(?string $desde = null, ?string $hasta = null): array
    {
        $pedidos = Pedido::query();
        if ($desde) {
            $pedidos->whereDate('created_at', '>=', $desde);
        }
        if ($hasta) {
            $pedidos->whereDate('created_at', '<=', $hasta);
        }

        $porEstado = (clone $pedidos)
            ->selectRaw('estado, COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')
            ->groupBy('estado')
            ->get()
            ->map(fn ($r) => [
                'estado'   => $r->estado,
                'cantidad' => (int) $r->cantidad,
                'monto'    => (float) $r->monto,
            ])
            ->values()
            ->all();

        $totalPedidos = (clone $pedidos)->count();
        $montoPedidos = (float) (clone $pedidos)->sum('total');

        $listaPedidos = (clone $pedidos)
            ->with([
                'clienteDirecto:id,nombre',
                'revendedorAfiliacion.revendedor:id,nombre',
                'capturadoPor.usuario:id,nombre',
                'detalle:id,pedido_id,producto_nombre,modelo,talla,color,cantidad',
            ])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(function (Pedido $p) {
                $quien = $p->clienteDirecto?->nombre
                    ?? $p->revendedorAfiliacion?->revendedor?->nombre
                    ?? '—';

                $capturadoPor = $p->capturadoPor?->usuario?->nombre;

                $descripcion = $p->detalle
                    ->map(fn ($d) => trim(
                        ($d->producto_nombre ?: $d->modelo)
                        .' · '.$d->talla
                        .' · '.$d->color
                        .' ×'.$d->cantidad
                    ))
                    ->implode('; ');

                if ($p->observaciones) {
                    $descripcion = ($descripcion ? $descripcion.' — ' : '').$p->observaciones;
                }

                return [
                    'id'            => $p->id,
                    'folio'         => $p->folio,
                    'estado'        => $p->estado,
                    'quien'         => $quien,
                    'tipo'          => $p->cliente_directo_id ? 'Cliente' : ($p->revendedor_distribuidora_id ? 'Revendedor' : '—'),
                    'capturado_por' => $capturadoPor,
                    'fecha'         => optional($p->fecha_colocacion ?? $p->created_at)?->format('d/m/Y H:i'),
                    'total'         => (float) $p->total,
                    'descripcion'   => $descripcion !== '' ? $descripcion : 'Sin líneas',
                ];
            })
            ->values()
            ->all();

        $valesActivos = Vale::query()->where('estado', 'activo')->count();
        $saldoVales = (float) Vale::query()->where('estado', 'activo')->sum('saldo_actual');

        $ventasDirectas = 0;
        $montoVentas = 0.0;
        $listaVentas = [];

        if (Schema::hasTable('ventas_directas')) {
            $vd = VentaDirecta::query();
            if ($desde) {
                $vd->whereDate('fecha_venta', '>=', $desde);
            }
            if ($hasta) {
                $vd->whereDate('fecha_venta', '<=', $hasta);
            }

            $ventasDirectas = (clone $vd)->count();
            $montoVentas = (float) (clone $vd)->sum('total');

            $listaVentas = (clone $vd)
                ->with([
                    'clienteDirecto:id,nombre',
                    'registradaPor.usuario:id,nombre',
                    'sucursal:id,nombre',
                    'detalle:id,venta_directa_id,producto_nombre,modelo,talla,color,cantidad',
                ])
                ->latest('fecha_venta')
                ->limit(100)
                ->get()
                ->map(function (VentaDirecta $v) {
                    $descripcion = $v->detalle
                        ->map(fn ($d) => trim(
                            ($d->producto_nombre ?: $d->modelo)
                            .' · '.$d->talla
                            .' · '.$d->color
                            .' ×'.$d->cantidad
                        ))
                        ->implode('; ');

                    return [
                        'id'            => $v->id,
                        'folio'         => $v->folio,
                        'quien'         => $v->clienteDirecto?->nombre ?? 'Público general',
                        'capturado_por' => $v->registradaPor?->usuario?->nombre,
                        'sucursal'      => $v->sucursal?->nombre,
                        'fecha'         => optional($v->fecha_venta)?->format('d/m/Y H:i'),
                        'estado'        => $v->estado,
                        'total'         => (float) $v->total,
                        'descripcion'   => $descripcion !== '' ? $descripcion : 'Sin líneas',
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'filtros' => [
                'desde' => $desde,
                'hasta' => $hasta,
            ],
            'pedidos' => [
                'total'       => $totalPedidos,
                'monto_total' => $montoPedidos,
                'por_estado'  => $porEstado,
                'lista'       => $listaPedidos,
            ],
            'vales' => [
                'activos'      => $valesActivos,
                'saldo_activo' => $saldoVales,
            ],
            'ventas_directas' => [
                'total'       => $ventasDirectas,
                'monto_total' => $montoVentas,
                'lista'       => $listaVentas,
            ],
        ];
    }
}