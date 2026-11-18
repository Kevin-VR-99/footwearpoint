{{--
    Pestañas de Tienda: Stock y Venta. Cada una abre la pantalla que ya existía.
    La subpestaña (conteo, historial, etc.) se conserva solo dentro de la misma
    pantalla. Quien puede abrir una puede abrir la otra: las dos rutas piden
    el mismo acceso de personal y los permisos finos se siguen revisando adentro.
--}}
@props(['activa' => 'stock'])

@php
    $pestanaActual = request()->query('pestana');
    $conservar = is_string($pestanaActual) && $pestanaActual !== '' ? ['pestana' => $pestanaActual] : [];
    $hrefStock = route('stock.index', $activa === 'stock' ? $conservar : []);
    $hrefVenta = route('punto-venta.index', $activa === 'venta' ? $conservar : []);
@endphp

<div class="fp-pestanas fp-pestanas-seccion mb-6" role="tablist" aria-label="Tienda" data-pestanas-tienda>
    <a href="{{ $hrefStock }}" wire:navigate role="tab"
        id="tab-tienda-stock"
        aria-selected="{{ $activa === 'stock' ? 'true' : 'false' }}"
        @if ($activa === 'stock') aria-current="page" @endif
        data-pestana-tienda="stock"
        @class([
            'fp-pestana',
            'fp-pestana-activa' => $activa === 'stock',
        ])>Stock</a>
    <a href="{{ $hrefVenta }}" wire:navigate role="tab"
        id="tab-tienda-venta"
        aria-selected="{{ $activa === 'venta' ? 'true' : 'false' }}"
        @if ($activa === 'venta') aria-current="page" @endif
        data-pestana-tienda="venta"
        @class([
            'fp-pestana',
            'fp-pestana-activa' => $activa === 'venta',
        ])>Venta</a>
</div>
