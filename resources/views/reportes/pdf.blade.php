<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte — {{ $casa->nombre_comercial }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0; color: #111E38; }
        h2 { font-size: 11px; margin: 14px 0 4px; color: #111E38; border-left: 3px solid #2563EB; padding-left: 6px; }
        .muted { color: #64748B; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #111E38; color: #fff; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; }
        td { padding: 3px 6px; border-bottom: 1px solid #E2E8F0; }
        tr:nth-child(even) td { background: #F5F6FA; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $casa->nombre_comercial }}</h1>
    <p class="muted">@if ($casa->rfc) RFC {{ $casa->rfc }} · @endif {{ $filtros }} · Generado el {{ now()->format('d/m/Y H:i') }}</p>

    @foreach ($secciones as $seccion)
        <h2>{{ $seccion['titulo'] }}</h2>
        <table>
            <thead><tr>@foreach ($seccion['encabezados'] as $e)<th>{{ $e }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($seccion['filas'] as $fila)
                    <tr>@foreach ($fila as $valor)<td class="{{ is_float($valor) ? 'num' : '' }}">{{ is_float($valor) ? '$'.number_format($valor, 2) : $valor }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($seccion['encabezados']) }}" class="muted">Sin datos con estos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
</body>
</html>
