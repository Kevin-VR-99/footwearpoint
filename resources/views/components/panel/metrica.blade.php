@props([
    'titulo',
    'valor',
    'detalle' => null,
    'tono' => 'primario',
    'icono' => 'M4 6h16M4 12h16M4 18h10',
    'progreso' => null,
])

@php
    $tonos = [
        'primario' => ['bg-fp-primary/10 text-fp-primary', 'bg-fp-primary'],
        'exito' => ['bg-fp-badge-success-bg text-fp-badge-success-fg', 'bg-fp-badge-success-fg'],
        'aviso' => ['bg-fp-badge-warning-bg text-fp-badge-warning-fg', 'bg-fp-badge-warning-fg'],
        'peligro' => ['bg-fp-danger-soft text-fp-danger', 'bg-fp-danger'],
        'neutral' => ['bg-slate-100 text-slate-500', 'bg-slate-400'],
    ];
    [$claseIcono, $claseBarra] = $tonos[$tono] ?? $tonos['primario'];
    $ancho = $progreso === null ? null : max(0, min(100, (int) round($progreso)));
@endphp

{{-- Tarjeta de métrica: se eleva al pasar el mouse, el icono reacciona y la barra se llena al aparecer. --}}
<div {{ $attributes->class(['fp-tarjeta-interactiva fp-metrica p-4']) }} data-metrica>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium text-fp-text-muted">{{ $titulo }}</p>
            <p class="fp-metrica-valor mt-2 whitespace-nowrap text-xl font-semibold tabular-nums sm:text-2xl 2xl:text-3xl tracking-tight text-fp-sidebar">{{ $valor }}</p>
        </div>
        <span class="fp-metrica-icono flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $claseIcono }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icono }}" />
            </svg>
        </span>
    </div>
    @if ($ancho !== null)
        <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-slate-100" role="presentation">
            <div class="fp-metrica-barra h-full rounded-full {{ $claseBarra }}" style="width: {{ $ancho }}%"></div>
        </div>
    @endif
    @if ($detalle)
        <p class="mt-3 text-[11px] text-fp-text-muted">{{ $detalle }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-2">{{ $slot }}</div>
    @endif
</div>
