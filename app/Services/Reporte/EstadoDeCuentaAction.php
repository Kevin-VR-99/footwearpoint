<?php

namespace App\Services\Reporte;

use App\Models\Pago;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estado de cuenta de la distribuidora: pagos aplicados del periodo,
 * con saldo anterior y saldo corrido. La suscripción a FootwearPoint
 * se anota como cargo, aunque el pago entre a la cuenta de la plataforma.
 */
class EstadoDeCuentaAction
{
    /**
     * @return array{
     *     saldo_anterior: float,
     *     cargos: float,
     *     abonos: float,
     *     saldo: float,
     *     movimientos: list<array{fecha: string, concepto: string, folio: string, cargo: float, abono: float, saldo: float}>
     * }
     */
    public function ejecutar(?string $desde, ?string $hasta, ?string $estadoPedido, ?int $cicloId): array
    {
        $saldo = $this->neto($this->consulta($estadoPedido, $cicloId, $desde, null, true));

        $movimientos = [];
        $cargos = 0.0;
        $abonos = 0.0;

        $pagos = $this->consulta($estadoPedido, $cicloId, $desde, $hasta, false)
            ->with([
                'pedido.clienteDirecto:id,nombre',
                'pedido.revendedorAfiliacion.revendedor:id,nombre',
                'ventaDirecta.clienteDirecto:id,nombre',
            ])
            ->orderByRaw('COALESCE(fecha_pago, created_at)')
            ->orderBy('id')
            ->get();

        foreach ($pagos as $pago) {
            [$cargo, $abono] = $this->partes($pago);
            $saldo = round($saldo + $abono - $cargo, 2);
            $cargos += $cargo;
            $abonos += $abono;

            $movimientos[] = [
                'fecha' => ($pago->fecha_pago ?? $pago->created_at)?->format('d/m/Y') ?? '—',
                'concepto' => $this->concepto($pago),
                'folio' => (string) $pago->folio,
                'cargo' => $cargo,
                'abono' => $abono,
                'saldo' => $saldo,
            ];
        }

        return [
            'saldo_anterior' => round($saldo - $abonos + $cargos, 2),
            'cargos' => round($cargos, 2),
            'abonos' => round($abonos, 2),
            'saldo' => round($saldo, 2),
            'movimientos' => $movimientos,
        ];
    }

    private function consulta(?string $estadoPedido, ?int $cicloId, ?string $desde, ?string $hasta, bool $antesDe): Builder
    {
        $consulta = Pago::query()->where('estado', 'aplicado');

        if ($cicloId !== null || ($estadoPedido !== null && $estadoPedido !== '')) {
            $consulta->whereHas('pedido', function (Builder $pedido) use ($cicloId, $estadoPedido) {
                if ($cicloId !== null) {
                    $pedido->where('ciclo_compra_id', $cicloId);
                }
                if ($estadoPedido !== null && $estadoPedido !== '') {
                    $pedido->where('estado', $estadoPedido);
                }
            });
        }

        $fecha = 'DATE(COALESCE(fecha_pago, created_at))';

        if ($antesDe && $desde !== null && $desde !== '') {
            $consulta->whereRaw($fecha.' < ?', [$desde]);
        }

        if (! $antesDe) {
            if ($desde !== null && $desde !== '') {
                $consulta->whereRaw($fecha.' >= ?', [$desde]);
            }
            if ($hasta !== null && $hasta !== '') {
                $consulta->whereRaw($fecha.' <= ?', [$hasta]);
            }
        }

        return $consulta;
    }

    /** @return array{0: float, 1: float} cargo, abono */
    private function partes(Pago $pago): array
    {
        $monto = round((float) $pago->monto, 2);

        if ($pago->tipo === 'suscripcion' || $pago->direccion === 'salida') {
            return [$monto, 0.0];
        }

        return [0.0, $monto];
    }

    private function neto(Builder $consulta): float
    {
        $entrada = (float) (clone $consulta)
            ->where('tipo', '!=', 'suscripcion')
            ->where('direccion', 'entrada')
            ->sum('monto');
        $salida = (float) (clone $consulta)
            ->where(function (Builder $filtro) {
                $filtro->where('tipo', 'suscripcion')->orWhere('direccion', 'salida');
            })
            ->sum('monto');

        return round($entrada - $salida, 2);
    }

    private function concepto(Pago $pago): string
    {
        if ($pago->tipo === 'suscripcion') {
            $extra = $pago->referencia === Pago::REFERENCIA_LINEA_EXTRA ? ' · línea extra' : '';

            return 'Suscripción FootwearPoint'.$extra;
        }

        $quien = $pago->pedido?->clienteDirecto?->nombre
            ?? $pago->pedido?->revendedorAfiliacion?->revendedor?->nombre
            ?? $pago->ventaDirecta?->clienteDirecto?->nombre;
        $documento = $pago->pedido?->folio ?? $pago->ventaDirecta?->folio;
        $partes = array_filter([
            Pago::etiquetaTipo($pago->tipo),
            $documento,
            $quien,
        ]);

        return implode(' · ', $partes);
    }
}
