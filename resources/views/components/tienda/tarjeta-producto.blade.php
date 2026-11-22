{{-- TG-233 (G14): un producto en la tienda pública. $producto es la ficha que arma TiendaPublica. --}}
@props(['producto', 'distribuidora'])

@php
    $tallas = collect($producto['tallas'])->pluck('talla')->unique()->values();
@endphp

<a href="{{ app(\App\Services\Tienda\EnlaceTienda::class)->producto($distribuidora, (int) $producto['id']) }}"
   class="group h-full fp-tarjeta-interactiva overflow-hidden flex flex-col">
    {{-- TG-407: la caja era cuadrada y la foto se agrandaba para llenarla, asi
         que al cliente le llegaba el zapato partido. Las fotos del catalogo son
         apaisadas: medidas las 16 de una tienda real, la mediana es 1.30 y doce
         caen entre 1.04 y 1.44. Con 4/3 y object-contain se ve entera, y la
         rejilla sigue pareja porque todas las tarjetas usan la misma forma.

         Tampoco se acerca al pasar el cursor. La caja recorta lo que se sale,
         asi que el acercamiento volvia a cortar el zapato justo al mirarlo de
         cerca. La tarjeta entera ya se levanta sola (fp-tarjeta-interactiva),
         que es la senial de que se puede pulsar. --}}
    <div class="aspect-[4/3] bg-slate-100 flex items-center justify-center overflow-hidden">
        @if ($producto['imagen'])
            <img src="{{ $producto['imagen_miniatura'] ?? $producto['imagen'] }}" alt="{{ $producto['nombre'] }}" loading="lazy" decoding="async"
                 width="400" height="300"
                 class="h-full w-full object-contain p-2 transition duration-200 motion-reduce:transform-none">
        @else
            <span class="text-slate-500 text-sm px-4 text-center">{{ $producto['marca'] ?? 'Sin foto' }}</span>
        @endif
    </div>

    <div class="p-4 flex-1 flex flex-col">
        @if ($producto['marca'])
            <p class="text-xs font-semibold uppercase tracking-wide text-fp-primary">{{ $producto['marca'] }}</p>
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
