@props([
    'variante' => 'neutral',
    'punto' => false,
    'viva' => false,
])

@php
    /*
     * A18 — Un solo vocabulario de estado en toda la casa:
     * neutral | info | exito | aviso | peligro.
     *
     * Es el espanol que ya usaba x-panel.boton (primario, peligro...); esta
     * insignia era la que desentonaba con success/warning/danger. Los nombres
     * viejos se siguen aceptando a proposito: estan en 84 llamadas, y 11 de
     * ellas son expresiones PHP que se resuelven en tiempo de ejecucion
     * (:variante="$x ? 'success' : 'neutral'"), asi que renombrarlas a ciegas
     * romperia pantallas. Lo nuevo se escribe en espanol; lo viejo sigue
     * funcionando.
     */
    $equivalencias = ['success' => 'exito', 'warning' => 'aviso', 'danger' => 'peligro'];
    $variante = $equivalencias[$variante] ?? $variante;
    $variante = in_array($variante, ['neutral', 'info', 'exito', 'aviso', 'peligro'], true) ? $variante : 'neutral';

    // Las clases de app.css siguen llamandose en ingles; se traducen aqui y
    // no se tocan 84 llamadas ni la hoja de estilos.
    $clase = ['neutral' => 'neutral', 'info' => 'info', 'exito' => 'success', 'aviso' => 'warning', 'peligro' => 'danger'][$variante];
@endphp

{{-- Insignia de estado con los colores de la casa; "viva" hace latir el punto (p. ej. pendiente, procesando). --}}
<span {{ $attributes->class(['fp-insignia', 'fp-insignia-'.$clase, 'fp-insignia-viva' => $viva]) }}>
    @if ($punto || $viva)
        <span class="fp-insignia-punto" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
