@props([
    'titulo' => null,
    'padding' => true,
])

{{-- TG-179: contenedor estándar del panel. --}}
<div {{ $attributes->class(['rounded-2xl border border-slate-200/80 bg-white shadow-sm', 'p-5 sm:p-6' => $padding]) }}>
    @if ($titulo || isset($acciones))
        <div @class(['flex flex-wrap items-center justify-between gap-3', 'mb-4' => $padding, 'border-b border-slate-100 px-5 py-4' => ! $padding])>
            @if ($titulo)
                <h3 class="text-sm font-semibold text-fp-sidebar">{{ $titulo }}</h3>
            @endif
            @isset($acciones)
                <div class="flex flex-wrap items-center gap-2">{{ $acciones }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
    @isset($pie)
        <div @class(['mt-4' => $padding, 'border-t border-slate-100 px-5 py-3' => ! $padding])>{{ $pie }}</div>
    @endisset
</div>
