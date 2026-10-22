{{-- Corte de caja en PDF (dompdf: tablas y estilos simples, sin flex ni variables CSS). --}}
@php
    $dinero = fn (float $m) => ($m < 0 ? '-$' : '$').number_format(abs($m), 2);
    $etiquetas = \App\Models\Pago::ETIQUETAS_METODO;
    $contado = $caja->contado ?? [];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte de caja {{ $caja->id }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #3e2723; }
        .sub { color: #64748b; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th { text-align: left; background: #f8fafc; color: #475569; font-size: 10px; text-transform: uppercase; padding: 6px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 6px; border-bottom: 1px solid #f1f5f9; }
        .der { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #cbd5e1; }
        .rojo { color: #b91c1c; } .verde { color: #047857; }
    </style>
</head>
<body>
    <h1>Corte de caja #{{ $caja->id }} — {{ $caja->distribuidora?->nombre_comercial }}</h1>
    <p class="sub">
        Sucursal {{ $caja->sucursal?->nombre }} ·
        Abrió {{ $caja->abiertaPor?->usuario?->nombreVisible() }} el {{ $caja->abierta_at?->format('d/m/Y H:i') }} ·
        @if ($caja->cerrada_at)
            Cerró {{ $caja->cerradaPor?->usuario?->nombreVisible() }} el {{ $caja->cerrada_at->format('d/m/Y H:i') }}
        @else
            Caja abierta (corte parcial)
        @endif
    </p>

    <table>
        <thead><tr><th>Método</th><th class="der">Cobros</th><th class="der">Devoluciones</th><th class="der">Ingresos</th><th class="der">Retiros</th><th class="der">Esperado</th><th class="der">Contado</th><th class="der">Diferencia</th></tr></thead>
        <tbody>
            @foreach ($r['metodos'] as $metodo => $m)
                @php $c = (float) ($contado[$metodo] ?? 0); $d = $caja->cerrada_at ? round($c - $m['esperado'], 2) : 0; @endphp
                <tr>
                    <td>{{ $etiquetas[$metodo] ?? $metodo }}@if ($metodo === 'efectivo') (fondo {{ $dinero((float) $caja->fondo_inicial) }})@endif</td>
                    <td class="der">{{ $dinero($m['cobros']) }}</td>
                    <td class="der">{{ $dinero($m['devoluciones']) }}</td>
                    <td class="der">{{ $dinero($m['ingresos']) }}</td>
                    <td class="der">{{ $dinero($m['retiros']) }}</td>
                    <td class="der">{{ $dinero($m['esperado']) }}</td>
                    <td class="der">{{ $caja->cerrada_at ? $dinero($c) : '—' }}</td>
                    <td class="der {{ $d < 0 ? 'rojo' : ($d > 0 ? '' : 'verde') }}">{{ $caja->cerrada_at ? $dinero($d) : '—' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5">Total</td>
                <td class="der">{{ $dinero($r['esperado_total']) }}</td>
                <td class="der">{{ $caja->cerrada_at ? $dinero(array_sum($contado)) : '—' }}</td>
                <td class="der">{{ $caja->cerrada_at ? $dinero((float) $caja->diferencia) : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <p><strong>{{ $r['ventas'] }}</strong> venta(s) de mostrador · <strong>{{ $r['canceladas'] }}</strong> cancelada(s)</p>

    <table>
        <thead><tr><th>Folio</th><th>Concepto</th><th>Método</th><th>Hora</th><th class="der">Monto</th></tr></thead>
        <tbody>
            @forelse ($r['pagos'] as $pago)
                <tr>
                    <td>{{ $pago->folio }}</td>
                    <td>{{ \App\Models\Pago::etiquetaTipo($pago->tipo) }}</td>
                    <td>{{ \App\Models\Pago::etiquetaMetodo($pago->metodo) }}</td>
                    <td>{{ $pago->fecha_pago?->format('H:i') }}</td>
                    <td class="der {{ $pago->direccion === 'salida' ? 'rojo' : '' }}">{{ $pago->direccion === 'salida' ? '-' : '' }}{{ $dinero((float) $pago->monto) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin cobros en esta caja.</td></tr>
            @endforelse
            @foreach ($r['movimientos'] as $mov)
                <tr>
                    <td>—</td><td>{{ $mov->tipo === 'retiro' ? 'Retiro' : 'Ingreso' }}: {{ $mov->motivo }}</td><td>Efectivo</td>
                    <td>{{ $mov->created_at?->format('H:i') }}</td>
                    <td class="der {{ $mov->tipo === 'retiro' ? 'rojo' : '' }}">{{ $mov->tipo === 'retiro' ? '-' : '' }}{{ $dinero((float) $mov->monto) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($caja->notas)
        <p class="sub">Notas: {{ $caja->notas }}</p>
    @endif
</body>
</html>
