{{-- TG-234: opción de la barra lateral con icono, igual que en layouts/panel. --}}
@props(['href', 'activo' => false, 'icono'])
<a href="{{ $href }}" {{ $attributes->class([
    'fp-nav-item group flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm transition',
    'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' => $activo,
    'text-white/80 hover:bg-white/10 hover:text-white' => ! $activo,
]) }} @if ($activo) aria-current="page" @endif data-enlace-lateral>
    <span @class([
        'flex h-7 w-7 items-center justify-center rounded-lg transition',
        'bg-fp-primary text-white' => $activo,
        'bg-white/10 text-white/70 group-hover:bg-white/15 group-hover:text-white' => ! $activo,
    ])>
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icono }}" />
        </svg>
    </span>
    {{ $slot }}
</a>
