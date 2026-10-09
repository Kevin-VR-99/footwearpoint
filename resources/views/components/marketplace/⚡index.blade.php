<?php

use App\Services\Directorio\DirectorioPublico;
use App\Services\Tienda\EnlaceTienda;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Marketplace — FootwearPoint')] class extends Component
{
    /**
     * TG-197 (G16) — Categoría elegida para filtrar (?categoria=ID). Se
     * guarda como texto para que una dirección mal escrita no truene: lo que
     * no es un número se trata como "todas".
     */
    #[Url(as: 'categoria', except: '')]
    public string $categoria = '';

    public function filtrar(?int $categoriaId = null): void
    {
        $this->categoria = $categoriaId ? (string) $categoriaId : '';
    }

    public function render()
    {
        $directorio = app(DirectorioPublico::class);
        $categoriaId = ctype_digit($this->categoria) && (int) $this->categoria > 0 ? (int) $this->categoria : null;

        return $this->view([
            'distribuidoras' => $directorio->distribuidoras($categoriaId),
            'categorias'     => $directorio->categorias(),
            'categoriaId'    => $categoriaId,
            // TG-234 (G15): enlace a la tienda pública de cada distribuidora.
            'enlaceTienda'   => app(EnlaceTienda::class),
        ]);
    }
};
?>

<div>
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-slate-900">Distribuidoras</h2>
        <p class="text-sm text-slate-500 mt-1">
            Encuentra distribuidoras de calzado afiliadas a FootwearPoint.
        </p>
    </div>

    {{-- TG-197 (G16): filtro por categorías del directorio --}}
    @if ($categorias->isNotEmpty())
        <div class="mb-6 flex flex-wrap gap-2" aria-label="Filtrar por categoría">
            <button type="button" wire:click="filtrar"
                class="px-3 py-1.5 rounded-full text-sm {{ $categoriaId === null ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                Todas
            </button>
            @foreach ($categorias as $cat)
                <button type="button" wire:click="filtrar({{ $cat->id }})"
                    class="px-3 py-1.5 rounded-full text-sm {{ $categoriaId === $cat->id ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                    {{ $cat->nombre }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($distribuidoras->isEmpty() && $categoriaId !== null)
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
            No hay distribuidoras en esta categoría por ahora.
            <button type="button" wire:click="filtrar" class="block mx-auto mt-3 text-sm text-[#2563EB] hover:underline">
                Ver todas las distribuidoras
            </button>
        </div>
    @elseif ($distribuidoras->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
            No hay distribuidoras visibles en el marketplace por ahora.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($distribuidoras as $d)
                @php($urlTienda = $enlaceTienda->url($d))
                <article class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                    <{{ $urlTienda ? 'a' : 'div' }} @if ($urlTienda) href="{{ $urlTienda }}" aria-label="Ver la tienda de {{ $d->nombre_comercial }}" @endif
                        class="h-28 bg-[#1E2F52] flex items-center justify-center">
                        @if ($d->logotipo_url)
                            <img src="{{ $d->logotipo_url }}"
                                 alt="Logo {{ $d->nombre_comercial }}"
                                 class="max-h-20 max-w-[80%] object-contain">
                        @else
                            <span class="text-white/80 text-lg font-semibold px-4 text-center">
                                {{ $d->nombre_comercial }}
                            </span>
                        @endif
                    </{{ $urlTienda ? 'a' : 'div' }}>

                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="text-lg font-semibold text-slate-900">
                            @if ($urlTienda)
                                <a href="{{ $urlTienda }}" class="hover:underline">{{ $d->nombre_comercial }}</a>
                            @else
                                {{ $d->nombre_comercial }}
                            @endif
                        </h3>

                        @if ($d->categoriasDirectorio->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($d->categoriasDirectorio as $cat)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-[#EEF2FF] text-[#1E2F52]">{{ $cat->nombre }}</span>
                                @endforeach
                            </div>
                        @endif

                        @if ($d->descripcion_publica)
                            <p class="text-sm text-slate-600 mt-2 line-clamp-3">
                                {{ $d->descripcion_publica }}
                            </p>
                        @endif

                        <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                            @if ($d->direccion_publica)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📍</span>
                                    <span>{{ $d->direccion_publica }}</span>
                                </li>
                            @endif
                            @if ($d->telefono_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📞</span>
                                    <a href="tel:{{ $d->telefono_publico }}" class="text-[#2563EB] hover:underline">
                                        {{ $d->telefono_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->email_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">✉️</span>
                                    <a href="mailto:{{ $d->email_publico }}" class="text-[#2563EB] hover:underline">
                                        {{ $d->email_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->horario_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">🕐</span>
                                    <span>{{ $d->horario_publico }}</span>
                                </li>
                            @endif
                        </ul>

                        @if ($urlTienda)
                            <div class="mt-auto pt-5">
                                <a href="{{ $urlTienda }}"
                                   aria-label="Ver la tienda de {{ $d->nombre_comercial }}"
                                   class="inline-flex w-full items-center justify-center rounded-lg bg-[#111E38] px-4 py-2 text-sm font-medium text-white hover:bg-[#1E2F52]">
                                    Ver tienda
                                </a>
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>