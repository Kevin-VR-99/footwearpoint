@props([
    'variante' => 'neutral',
    'punto' => false,
    'viva' => false,
])

@php($variante = in_array($variante, ['neutral', 'info', 'success', 'warning', 'danger'], true) ? $variante : 'neutral')

{{-- Insignia de estado con los colores de la casa; "viva" hace latir el punto (p. ej. pendiente, procesando). --}}
<span {{ $attributes->class(['fp-insignia', 'fp-insignia-'.$variante, 'fp-insignia-viva' => $viva]) }}>
    @if ($punto || $viva)
        <span class="fp-insignia-punto" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
