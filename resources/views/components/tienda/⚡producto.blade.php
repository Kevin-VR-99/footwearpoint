<?php

use App\Services\Tienda\TiendaPublica;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| TG-233 (G14) — Un producto en la tienda pública
| (/tienda/{slug}/productos/{productoCampana}).
|
| Si la distribuidora no lo vende (otra línea, lo ocultó, ya no hay tallas
| que se puedan pedir) o no existe, es el mismo 404: desde fuera no tiene
| por qué saberse qué hay en el catálogo de los demás.
*/
new class extends Component {
    #[Locked]
    public string $slug = '';

    #[Locked]
    public int $productoId = 0;

    public function mount(string $slug, string $productoCampana): void
    {
        abort_unless(ctype_digit($productoCampana) && (int) $productoCampana > 0, 404);

        $tienda = app(TiendaPublica::class);
        $tienda->producto($tienda->distribuidora($slug), (int) $productoCampana);

        $this->slug = $slug;
        $this->productoId = (int) $productoCampana;
    }

    public function render()
    {
        $tienda = app(TiendaPublica::class);
        $distribuidora = $tienda->distribuidora($this->slug);
        $producto = $tienda->producto($distribuidora, $this->productoId);

        return $this->view([
            'distribuidora' => $distribuidora,
            'producto'      => $producto,
        ])
            ->layout('layouts.tienda', ['distribuidora' => $distribuidora])
            ->title($producto['nombre'].' — '.$distribuidora->nombre_comercial.' | FootwearPoint');
    }
};
?>

<div class="space-y-6">
    <a href="{{ app(\App\Services\Tienda\EnlaceTienda::class)->url($distribuidora) }}" class="text-sm text-[#2563EB] hover:underline">
        ← Volver a la tienda
    </a>

    <article class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden grid md:grid-cols-2">
        <div class="bg-slate-100">
            @if ($producto['imagen'])
                <img src="{{ $producto['imagen'] }}" alt="{{ $producto['nombre'] }}" class="w-full aspect-square object-cover">
                @if (count($producto['imagenes']) > 1)
                    <div class="grid grid-cols-4 gap-2 p-3">
                        @foreach (array_slice($producto['imagenes'], 1) as $url)
                            <img src="{{ $url }}" alt="{{ $producto['nombre'] }}" loading="lazy"
                                 class="w-full aspect-square object-cover rounded-lg bg-white">
                        @endforeach
                    </div>
                @endif
            @else
                <div class="aspect-square flex items-center justify-center text-slate-400">
                    {{ $producto['marca'] ?? 'Sin foto' }}
                </div>
            @endif
        </div>

        <div class="p-6 flex flex-col gap-4">
            <div>
                @if ($producto['marca'])
                    <p class="text-xs font-semibold uppercase tracking-wide text-[#2563EB]">{{ $producto['marca'] }}</p>
                @endif
                <h2 class="mt-1 text-2xl font-bold text-slate-900">{{ $producto['nombre'] }}</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Modelo {{ $producto['modelo'] }}
                    @if ($producto['codigo'])
                        · Código {{ $producto['codigo'] }}
                    @endif
                </p>
                @if ($producto['linea'] || $producto['temporada'])
                    <p class="text-sm text-slate-500">
                        {{ collect([$producto['linea'], $producto['temporada']])->filter()->implode(' · ') }}
                    </p>
                @endif
            </div>

            <div>
                <p class="text-xs text-slate-500">Precio</p>
                <p class="text-3xl font-bold text-slate-900">{{ $producto['precio_texto'] }}</p>
            </div>

            @if ($producto['colores'])
                <p class="text-sm text-slate-600"><span class="text-slate-500">Color:</span> {{ implode(', ', $producto['colores']) }}</p>
            @endif

            <div>
                <p class="text-sm font-semibold text-slate-900 mb-2">Tallas</p>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($producto['tallas'] as $talla)
                        <li class="px-3 py-1.5 rounded-lg border text-sm {{ $talla['bajo_pedido'] ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-800' }}">
                            {{ $talla['talla'] }}
                            @if ($talla['bajo_pedido'])
                                <span class="text-xs">· Bajo pedido</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($producto['hay_bajo_pedido'])
                    <p class="text-xs text-slate-500 mt-2">Las tallas bajo pedido se encargan a la fábrica.</p>
                @endif
            </div>

            @if ($producto['descripcion'])
                <p class="text-sm text-slate-600">{{ $producto['descripcion'] }}</p>
            @endif

            <div class="mt-auto rounded-lg bg-[#EEF2FF] p-4 text-sm text-[#1E2F52]">
                ¿Te interesa? Pídelo en mostrador o desde la app de FootwearPoint.
                @if ($distribuidora->telefono_publico)
                    También puedes llamar al
                    <a href="tel:{{ $distribuidora->telefono_publico }}" class="font-semibold hover:underline">{{ $distribuidora->telefono_publico }}</a>.
                @endif
            </div>
        </div>
    </article>
</div>
