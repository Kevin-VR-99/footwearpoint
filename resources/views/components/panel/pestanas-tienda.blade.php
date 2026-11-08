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

<div class="fp-pestanas mb-6 flex flex-wrap gap-1" role="tablist" aria-label="Tienda" data-pestanas-tienda>
    <a href="{{ $hrefStock }}" wire:navigate role="tab"
        id="tab-tienda-stock"
        aria-selected="{{ $activa === 'stock' ? 'true' : 'false' }}"
        data-pestana-tienda="stock"
        @class([
            'fp-pestana rounded-lg px-3.5 py-2 text-sm font-medium transition',
            'bg-fp-primary text-white shadow-sm' => $activa === 'stock',
            'text-slate-600 hover:bg-slate-50' => $activa !== 'stock',
        ])>Stock</a>
    <a href="{{ $hrefVenta }}" wire:navigate role="tab"
        id="tab-tienda-venta"
        aria-selected="{{ $activa === 'venta' ? 'true' : 'false' }}"
        data-pestana-tienda="venta"
        @class([
            'fp-pestana rounded-lg px-3.5 py-2 text-sm font-medium transition',
            'bg-fp-primary text-white shadow-sm' => $activa === 'venta',
            'text-slate-600 hover:bg-slate-50' => $activa !== 'venta',
        ])>Venta</a>
</div>
