{{-- Tarjeta de sección con título, nota opcional, acciones y contenido (tablas, gráficas). --}}
@props(['titulo', 'nota' => null, 'id' => null])
<section @if ($id) id="{{ $id }}" @endif {{ $attributes->class('fp-tarjeta fp-aparecer overflow-hidden') }}>
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-fp-sidebar">{{ $titulo }}</h3>
            @if ($nota)
                <p class="mt-0.5 text-xs text-slate-500">{{ $nota }}</p>
            @endif
        </div>
        @isset($acciones)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $acciones }}</div>
        @endisset
    </header>
    {{ $slot }}
</section>
