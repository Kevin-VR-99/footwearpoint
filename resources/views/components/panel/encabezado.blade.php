@props([
    'titulo',
    'subtitulo' => null,
    'eyebrow' => null,
    'volver' => null,
    'volverTexto' => null,
    'nivel' => 'h1',
])

{{-- TG-179: encabezado de página del panel (patrón de referencia del Inicio). --}}
<div {{ $attributes->class(['fp-tarjeta fp-aparecer relative overflow-hidden']) }}>
    <div class="absolute inset-y-0 left-0 w-1.5 bg-fp-primary" aria-hidden="true"></div>
    <div class="relative flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="min-w-0">
            @if ($volver)
                <a href="{{ $volver }}"
                    class="fp-enlace text-sm">{{ $volverTexto }}</a>
            @endif
            @if ($eyebrow)
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">{{ $eyebrow }}</p>
            @endif
            <{{ $nivel }} @class(['text-2xl font-bold tracking-tight text-fp-sidebar', 'mt-1' => $volver || $eyebrow])>{{ $titulo }}</{{ $nivel }}>
            @if ($subtitulo)
                <p class="mt-1 text-sm text-fp-text-muted">{{ $subtitulo }}</p>
            @endif
            {{ $slot }}
        </div>
        @isset($acciones)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {{ $acciones }}
            </div>
        @endisset
    </div>
</div>
