{{--
    Pestañas de Compras. Cada una abre la pantalla correspondiente (Ciclo, Pedidos, Vales y Anticipos).
--}}
@props(['activa' => 'ciclo'])

@php
    $pestanas = [
        'ciclo' => [route('ciclo.index'), 'Ciclo'],
        'pedidos' => [route('pedidos.index'), 'Pedidos'],
        'vales' => [route('vales.index'), 'Vales'],
        'anticipos' => [route('compras.anticipos'), 'Anticipos'],
    ];
@endphp

<div class="fp-pestanas fp-pestanas-seccion mb-6" role="tablist" aria-label="Compras" data-pestanas-compras>
    @foreach ($pestanas as $clave => [$href, $texto])
        <a href="{{ $href }}" wire:navigate role="tab"
            id="tab-compras-{{ $clave }}"
            aria-selected="{{ $activa === $clave ? 'true' : 'false' }}"
            @if ($activa === $clave) aria-current="page" @endif
            data-pestana-compras="{{ $clave }}"
            @class([
                'fp-pestana',
                'fp-pestana-activa' => $activa === $clave,
            ])>{{ $texto }}</a>
    @endforeach
</div>
