<?php

use App\Services\Directorio\DirectorioPublico;
use App\Services\Tienda\EnlaceTienda;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Marketplace — FootwearPoint')] class extends Component
{
    /** Buscador unificado: nombre, ciudad, colonia o dirección. Vacío muestra todas las visibles. */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** Enlaces viejos con ?lugar=… se pasan al buscador unificado. */
    public function mount(): void
    {
        $lugar = trim((string) request()->query('lugar', ''));
        if ($lugar !== '' && trim($this->q) === '') {
            $this->q = $lugar;
        }
    }

    public function render()
    {
        $directorio = app(DirectorioPublico::class);

        return $this->view([
            'distribuidoras' => $directorio->distribuidoras(null, null, null, $this->q),
            'buscando'       => trim($this->q) !== '',
            // TG-234 (G15): enlace a la tienda pública de cada distribuidora.
            'enlaceTienda'   => app(EnlaceTienda::class),
        ]);
    }
};
?>

<div>
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-0.5">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Distribuidoras</h1>
            <p class="text-sm text-slate-500">
                {{ $distribuidoras->count() }} {{ $distribuidoras->count() === 1 ? 'distribuidora' : 'distribuidoras' }}
            </p>
        </div>
        <a href="{{ route('inicio') }}" class="text-sm font-medium fp-enlace">
            Volver al inicio
        </a>
    </div>

    {{-- La barra pública mide 4.125rem (franja + fila del logo). El buscador se queda debajo al hacer scroll. --}}
    <form class="sticky top-[4.125rem] z-20 -mx-4 mb-6 grid grid-cols-1 gap-3 border-b border-slate-200/80 bg-fp-page/95 px-4 py-3 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-fp-page/80 sm:-mx-5 sm:grid-cols-2 sm:px-5 fp-aparecer"
        role="search">
        <div class="sm:col-span-2">
            <p id="etiqueta-buscar-distribuidora" class="mb-1 text-sm font-medium text-slate-700">Buscar distribuidora</p>
            <x-ui.buscador id="buscar-distribuidora" wire:model.live.debounce.300ms="q"
                placeholder="Nombre, ciudad, colonia o dirección" etiqueta="Buscar distribuidora por nombre, ciudad, colonia o dirección" class="w-full"
                aria-labelledby="etiqueta-buscar-distribuidora" aria-describedby="ayuda-buscar-distribuidora" />
            <p id="ayuda-buscar-distribuidora" class="mt-1 text-xs text-fp-text-muted">Escribe el nombre de la distribuidora o el lugar: ciudad, colonia o calle.</p>
        </div>
        <p wire:loading.delay.shortest class="text-sm font-medium text-slate-500 sm:col-span-2" aria-live="polite">Buscando…</p>
    </form>

    @if ($distribuidoras->isEmpty() && $buscando)
        <div class="fp-aparecer fp-tarjeta p-10 text-center text-slate-500">
            Ninguna distribuidora coincide con esa búsqueda.
        </div>
    @elseif ($distribuidoras->isEmpty())
        <div class="fp-aparecer fp-tarjeta p-10 text-center text-slate-500">
            No hay distribuidoras visibles en el marketplace por ahora.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 fp-escalonado">
            @foreach ($distribuidoras as $d)
                @php($urlTienda = $enlaceTienda->url($d))
                <article wire:key="dist-{{ $d->id }}" class="group fp-tarjeta-interactiva overflow-hidden flex flex-col">
                    <{{ $urlTienda ? 'a' : 'div' }} @if ($urlTienda) href="{{ $urlTienda }}" aria-label="Ver la tienda de {{ $d->nombre_comercial }}" @endif
                        class="flex h-40 items-center justify-center bg-fp-page">
                        <span class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full bg-white shadow-md ring-1 ring-slate-200 transition duration-300 group-hover:scale-105 group-hover:ring-fp-primary/40 motion-reduce:transform-none">
                            @if ($d->logotipo_url)
                                <img src="{{ $d->logotipo_url }}"
                                     alt="Logo {{ $d->nombre_comercial }}"
                                     class="h-full w-full object-contain p-3">
                            @else
                                <span class="px-3 text-center text-sm font-semibold text-fp-sidebar">
                                    {{ $d->nombre_comercial }}
                                </span>
                            @endif
                        </span>
                    </{{ $urlTienda ? 'a' : 'div' }}>

                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="text-lg font-semibold text-slate-900">
                            @if ($urlTienda)
                                <a href="{{ $urlTienda }}" class="hover:underline">{{ $d->nombre_comercial }}</a>
                            @else
                                {{ $d->nombre_comercial }}
                            @endif
                        </h3>

                        @if ($d->descripcion_publica)
                            <p class="text-sm text-slate-600 mt-2 line-clamp-3">
                                {{ $d->descripcion_publica }}
                            </p>
                        @endif

                        <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                            @php($direccionMapa = \App\Support\MapaUbicacion::direccion($d))
                            @if ($direccionMapa)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📍</span>
                                    <span>
                                        {{ $direccionMapa }}
                                        <a href="{{ \App\Support\MapaUbicacion::urlVerEnMapa($d) }}" target="_blank" rel="noopener" class="ml-1 whitespace-nowrap fp-enlace">Ver en mapa</a>
                                    </span>
                                </li>
                            @endif

                            @if ($d->telefono_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📞</span>
                                    <a href="tel:{{ $d->telefono_publico }}" class="fp-enlace">
                                        {{ $d->telefono_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->email_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">✉️</span>
                                    <a href="mailto:{{ $d->email_publico }}" class="fp-enlace">
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
                                   class="fp-btn fp-btn-oscuro w-full rounded-xl py-2.5">
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