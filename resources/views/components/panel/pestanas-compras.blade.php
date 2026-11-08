{{--
    Pestañas de Compras. Cada una abre la pantalla que ya existía.
    Anticipos está en la navegación y todavía no muestra configuración ni listado.
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

<div class="fp-pestanas mb-6 flex flex-wrap gap-1" role="tablist" aria-label="Compras" data-pestanas-compras>
    @foreach ($pestanas as $clave => [$href, $texto])
        <a href="{{ $href }}" wire:navigate role="tab"
            id="tab-compras-{{ $clave }}"
            aria-selected="{{ $activa === $clave ? 'true' : 'false' }}"
            data-pestana-compras="{{ $clave }}"
            @class([
                'fp-pestana rounded-lg px-3.5 py-2 text-sm font-medium transition',
                'bg-fp-primary text-white shadow-sm' => $activa === $clave,
                'text-slate-600 hover:bg-slate-50' => $activa !== $clave,
            ])>{{ $texto }}</a>
    @endforeach
</div>
