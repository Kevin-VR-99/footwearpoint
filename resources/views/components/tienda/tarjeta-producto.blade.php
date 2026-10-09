{{-- TG-233 (G14): un producto en la tienda pública. $producto es la ficha que arma TiendaPublica. --}}
@props(['producto', 'distribuidora'])

@php
    $tallas = collect($producto['tallas'])->pluck('talla')->unique()->values();
@endphp

<a href="{{ app(\App\Services\Tienda\EnlaceTienda::class)->producto($distribuidora, (int) $producto['id']) }}"
   class="group h-full bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col hover:shadow-md transition">
    <div class="aspect-square bg-slate-100 flex items-center justify-center overflow-hidden">
        @if ($producto['imagen'])
            <img src="{{ $producto['imagen'] }}" alt="{{ $producto['nombre'] }}" loading="lazy"
                 class="h-full w-full object-cover group-hover:scale-105 transition">
        @else
            <span class="text-slate-400 text-sm px-4 text-center">{{ $producto['marca'] ?? 'Sin foto' }}</span>
        @endif
    </div>

    <div class="p-4 flex-1 flex flex-col">
        @if ($producto['marca'])
            <p class="text-xs font-semibold uppercase tracking-wide text-[#2563EB]">{{ $producto['marca'] }}</p>
        @endif
        <h3 class="mt-1 text-sm font-semibold text-slate-900 line-clamp-2">{{ $producto['nombre'] }}</h3>
        @if ($producto['colores'])
            <p class="text-xs text-slate-500 mt-0.5">{{ implode(', ', $producto['colores']) }}</p>
        @endif

        <div class="mt-auto pt-3">
            <p class="text-xs text-slate-500">Precio</p>
            <p class="text-lg font-bold text-slate-900">{{ $producto['precio_texto'] }}</p>
            @if ($tallas->isNotEmpty())
                <p class="text-xs text-slate-500 mt-1">
                    Tallas {{ $tallas->count() > 1 ? $tallas->first().' a '.$tallas->last() : $tallas->first() }}
                </p>
            @endif
            @if ($producto['hay_bajo_pedido'])
                <span class="inline-block mt-2 text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">
                    Algunas tallas bajo pedido
                </span>
            @endif
        </div>
    </div>
</a>
