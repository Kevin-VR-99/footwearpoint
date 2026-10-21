@props([
    'mensaje' => null,
    'colspan' => null,
    'icono' => 'M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0h-4l-2 3h-4l-2-3H4',
])

{{-- TG-179: estado vacío. Con colspan se pinta como fila de tabla. Icono suave que flota (sin movimiento con reduced-motion). --}}
@php
    $contenido = '<span class="fp-vacio-icono mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-fp-page text-fp-text-muted ring-1 ring-slate-200/80"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.e($icono).'" /></svg></span>';
@endphp
@if ($colspan)
    <tr>
        <td colspan="{{ $colspan }}" {{ $attributes->class(['fp-desvanecer px-4 py-10 text-center text-sm text-fp-text-muted']) }}>
            {!! $contenido !!}
            {{ $mensaje }}{{ $slot }}
        </td>
    </tr>
@else
    <div {{ $attributes->class(['fp-desvanecer px-4 py-10 text-center text-sm text-fp-text-muted']) }}>
        {!! $contenido !!}
        {{ $mensaje }}{{ $slot }}
    </div>
@endif
