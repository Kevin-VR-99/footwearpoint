{{-- Capa de carga: cubre la tarjeta (que debe ser relative) mientras Livewire responde.
     Con .delay solo aparece si la respuesta tarda, para no parpadear. --}}
@props(['texto' => 'Cargando…', 'objetivo' => null])
<div wire:loading.delay.flex @if ($objetivo) wire:target="{{ $objetivo }}" @endif
    {{ $attributes->class('fp-desvanecer absolute inset-0 z-10 items-center justify-center bg-white/60 backdrop-blur-[1px]') }}
    role="status" aria-live="polite" data-cargando>
    <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-fp-sidebar shadow ring-1 ring-slate-200">
        <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-fp-primary border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
        {{ $texto }}
    </span>
</div>
