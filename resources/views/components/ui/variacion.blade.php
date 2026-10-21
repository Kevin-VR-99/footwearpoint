{{--
    Variación contra el periodo anterior de la misma duración.
    - anterior null: no hay comparación posible ("—").
    - anterior 0: «Nuevo» si ahora hay valor, "—" si tampoco.
    - conviene: 'subir' (más es mejor) o 'bajar' (menos es mejor) decide el color.
--}}
@props(['actual', 'anterior' => null, 'conviene' => 'subir', 'etiqueta' => 'vs periodo anterior'])

@php
    $actual = (float) $actual;
    $clase = 'text-fp-text-muted';
    $flecha = null;
    $texto = '—';
    $titulo = 'Sin periodo anterior para comparar';

    if ($anterior !== null) {
        $anterior = (float) $anterior;
        if (abs($anterior) < 0.00001) {
            if (abs($actual) > 0.00001) {
                $texto = 'Nuevo';
                $clase = 'text-fp-primary';
                $titulo = 'En el periodo anterior no hubo movimiento';
            } else {
                $titulo = 'Sin movimiento en ambos periodos';
            }
        } else {
            $pct = ($actual - $anterior) / abs($anterior) * 100;
            $redondeado = round($pct, 1);
            $titulo = 'Periodo anterior: '.number_format($anterior, 2);
            if ($redondeado == 0.0) {
                $texto = '0%';
            } else {
                $sube = $redondeado > 0;
                $bueno = $conviene === 'bajar' ? ! $sube : $sube;
                $flecha = $sube ? 'arriba' : 'abajo';
                $clase = $bueno ? 'text-fp-badge-success-fg' : 'text-fp-danger';
                $texto = ($sube ? '+' : '').number_format($redondeado, 1).'%';
            }
        }
    }
@endphp

<span {{ $attributes->merge(['class' => 'fp-variacion inline-flex items-center gap-1 text-[11px] font-semibold '.$clase]) }}
    title="{{ $titulo }}" data-variacion="{{ $texto }}">
    @if ($flecha === 'arriba')
        <svg class="h-3 w-3 motion-safe:animate-[fp-sube_.5s_ease-out]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4l6 7h-4v5H8v-5H4z"/></svg>
        <span class="sr-only">Sube</span>
    @elseif ($flecha === 'abajo')
        <svg class="h-3 w-3 motion-safe:animate-[fp-baja_.5s_ease-out]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 16l-6-7h4V4h4v5h4z"/></svg>
        <span class="sr-only">Baja</span>
    @endif
    <span>{{ $texto }}</span>
    <span class="font-normal text-fp-text-muted">{{ $etiqueta }}</span>
</span>
