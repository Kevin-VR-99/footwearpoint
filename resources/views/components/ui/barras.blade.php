{{-- Gráfica de barras horizontal ligera (solo HTML/CSS, sin librerías).
     $items: lista de ['etiqueta' => string, 'valor' => número, 'texto' => string opcional]. --}}
@props(['items' => [], 'tono' => 'primario', 'vacio' => 'Sin datos para graficar'])
@php
    $max = max(1, ...array_map(fn ($i) => (float) $i['valor'], $items ?: [['valor' => 0]]));
    $color = ['primario' => 'bg-fp-primary', 'exito' => 'bg-fp-badge-success-fg', 'aviso' => 'bg-amber-500', 'peligro' => 'bg-fp-danger', 'oscuro' => 'bg-fp-sidebar'][$tono] ?? 'bg-fp-primary';
@endphp
@if ($items === [])
    <p {{ $attributes->class('px-1 py-4 text-center text-sm text-slate-500') }}>{{ $vacio }}</p>
@else
    <ul {{ $attributes->class('fp-escalonado space-y-2.5') }} role="list" data-grafica-barras>
        @foreach ($items as $item)
            @php
                $pct = round(((float) $item['valor'] / $max) * 100, 1);
            @endphp
            <li class="fp-aparecer">
                <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                    <span class="min-w-0 truncate font-medium text-slate-700" title="{{ $item['etiqueta'] }}">{{ $item['etiqueta'] }}</span>
                    <span class="shrink-0 tabular-nums text-slate-500">{{ $item['texto'] ?? $item['valor'] }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="{{ $item['etiqueta'] }}: {{ $item['texto'] ?? $item['valor'] }}">
                    <div class="fp-metrica-barra h-full rounded-full {{ $color }}" style="width: {{ max($pct, 2) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
