<?php

use App\Services\Directorio\DirectorioPublico;
use App\Services\Tienda\EnlaceTienda;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * El directorio público de distribuidoras (/marketplace).
 *
 * TG-391 (A31) — Marketplace completo: además del buscador unificado se
 * puede filtrar por la línea que vende cada distribuidora (Cklass, Impuls...),
 * ordenar y elegir entre tarjetas o lista. Todo vive en la dirección
 * (?q=, ?linea=, ?orden=, ?vista=) para que un enlace compartido abra igual.
 *
 * Las categorías del directorio NO vuelven: se quitaron a propósito (ver
 * MarketplaceDirectorioTest). La "categoría" que sirve al visitante es la
 * línea, que además es la misma con la que filtra la tienda de cada una.
 */
new #[Layout('layouts.public')] #[Title('Marketplace — FootwearPoint')] class extends Component
{
    public const ORDENES = ['nombre' => 'Nombre (A–Z)', 'lineas' => 'Más líneas primero'];

    public const VISTAS = ['tarjetas', 'lista'];

    /** Líneas que se enseñan en cada tarjeta antes del "+N". */
    public const LINEAS_POR_TARJETA = 5;

    /** Buscador unificado: nombre, ciudad, colonia o dirección. Vacío muestra todas las visibles. */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** TG-391: id de la línea por la que se filtra; vacío es "todas". Texto para que la URL no truene. */
    #[Url(as: 'linea', except: '')]
    public string $linea = '';

    #[Url(as: 'orden', except: 'nombre')]
    public string $orden = 'nombre';

    #[Url(as: 'vista', except: 'tarjetas')]
    public string $vista = 'tarjetas';

    /** Enlaces viejos con ?lugar=… se pasan al buscador unificado. */
    public function mount(): void
    {
        $lugar = trim((string) request()->query('lugar', ''));
        if ($lugar !== '' && trim($this->q) === '') {
            $this->q = $lugar;
        }
    }

    public function filtrarLinea(?int $lineaId = null): void
    {
        $this->linea = $lineaId ? (string) $lineaId : '';
    }

    public function cambiarVista(string $vista): void
    {
        $this->vista = in_array($vista, self::VISTAS, true) ? $vista : 'tarjetas';
    }

    public function limpiarFiltros(): void
    {
        $this->q = '';
        $this->linea = '';
    }

    public function render()
    {
        $directorio = app(DirectorioPublico::class);

        $lineas = $directorio->lineas();

        // Un ?linea= que no es número o que ya nadie vende se ignora: mejor
        // ver todas que una página vacía por un enlace viejo.
        $lineaId = ctype_digit($this->linea) ? (int) $this->linea : null;
        $lineaElegida = $lineaId !== null ? $lineas->firstWhere('id', $lineaId) : null;
        $lineaId = $lineaElegida?->id;

        $orden = array_key_exists($this->orden, self::ORDENES) ? $this->orden : 'nombre';
        $vista = in_array($this->vista, self::VISTAS, true) ? $this->vista : 'tarjetas';

        $distribuidoras = $directorio->distribuidoras(null, null, null, $this->q, $lineaId);
        $lineasDe = $directorio->lineasPorDistribuidora($distribuidoras->pluck('id'));

        if ($orden === 'lineas') {
            $distribuidoras = $distribuidoras
                ->sortBy([
                    fn ($a, $b) => count($lineasDe[$b->id] ?? []) <=> count($lineasDe[$a->id] ?? []),
                    fn ($a, $b) => strcasecmp((string) $a->nombre_comercial, (string) $b->nombre_comercial),
                ])
                ->values();
        }

        return $this->view([
            'distribuidoras' => $distribuidoras,
            'buscando'       => trim($this->q) !== '',
            'filtrando'      => trim($this->q) !== '' || $lineaId !== null,
            'lineas'         => $lineas,
            'lineaId'        => $lineaId,
            'lineaElegida'   => $lineaElegida,
            'lineasDe'       => $lineasDe,
            'ordenActual'    => $orden,
            'ordenes'        => self::ORDENES,
            'porTarjeta'     => self::LINEAS_POR_TARJETA,
            'vistaActual'    => $vista,
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
            <p class="text-sm text-slate-500" aria-live="polite" data-total-distribuidoras>
                {{ $distribuidoras->count() }} {{ $distribuidoras->count() === 1 ? 'distribuidora' : 'distribuidoras' }}
                @if ($lineaElegida)
                    · venden {{ $lineaElegida->nombre }}
                @endif
            </p>
        </div>
        <a href="{{ route('inicio') }}" class="text-sm font-medium fp-enlace">
            Volver al inicio
        </a>
    </div>

    {{-- La barra pública mide 4.125rem (franja + fila del logo). El buscador se queda debajo al hacer scroll.
         Fondo sólido: aquí se lee y se escribe (TG-389 A29, glass solo de acento). --}}
    <form class="sticky top-[4.125rem] z-20 -mx-4 mb-6 grid grid-cols-1 gap-3 border-b border-slate-200/80 bg-fp-page px-4 py-3 shadow-sm sm:-mx-5 sm:grid-cols-2 sm:px-5 fp-aparecer"
        role="search" wire:submit.prevent>
        <div class="sm:col-span-2">
            <p id="etiqueta-buscar-distribuidora" class="mb-1 text-sm font-medium text-slate-700">Buscar distribuidora</p>
            <x-ui.buscador id="buscar-distribuidora" wire:model.live.debounce.300ms="q"
                placeholder="Nombre, ciudad, colonia o dirección" etiqueta="Buscar distribuidora por nombre, ciudad, colonia o dirección" class="w-full"
                aria-labelledby="etiqueta-buscar-distribuidora" aria-describedby="ayuda-buscar-distribuidora" />
            <p id="ayuda-buscar-distribuidora" class="mt-1 text-xs text-fp-text-muted">Escribe el nombre de la distribuidora o el lugar: ciudad, colonia o calle.</p>
        </div>
        <p wire:loading.delay.shortest class="text-sm font-medium text-slate-500 sm:col-span-2" aria-live="polite">Buscando…</p>
    </form>

    {{-- TG-391: filtros, orden y vista. Fuera del buscador pegado arriba a
         propósito: en el celular cuatro filas fijas se comerían media pantalla. --}}
    <div class="-mt-3 mb-6 grid grid-cols-1 gap-3" data-controles-marketplace>
        @if ($lineas->isNotEmpty())
            <div class="min-w-0" data-filtro-lineas>
                <p id="etiqueta-filtrar-linea" class="mb-1.5 text-xs font-semibold uppercase tracking-[0.12em] text-fp-text-muted">Filtrar por línea</p>
                <div class="-mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0"
                    role="group" aria-labelledby="etiqueta-filtrar-linea">
                    <button type="button" wire:click="filtrarLinea()" aria-pressed="{{ $lineaId === null ? 'true' : 'false' }}"
                        class="fp-chip shrink-0 snap-start rounded-full px-3.5 py-1.5 text-sm font-medium shadow-sm {{ $lineaId === null ? 'bg-fp-sidebar text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Todas
                    </button>
                    @foreach ($lineas as $l)
                        <button type="button" wire:key="filtro-linea-{{ $l->id }}" wire:click="filtrarLinea({{ $l->id }})"
                            aria-pressed="{{ $lineaId === $l->id ? 'true' : 'false' }}" data-filtro-linea="{{ $l->id }}"
                            class="fp-chip inline-flex shrink-0 snap-start items-center gap-2 rounded-full py-1 pl-1 pr-3.5 text-sm font-medium shadow-sm {{ $lineaId === $l->id ? 'bg-fp-sidebar text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">
                            <x-ui.avatar-linea :nombre="$l->nombre" :url="$l->logotipo_url" tamano="sm" />
                            {{ $l->nombre }}
                            <span class="text-xs {{ $lineaId === $l->id ? 'text-white/70' : 'text-fp-text-muted' }}">({{ $l->total }})</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <label for="orden-distribuidoras" class="text-sm font-medium text-slate-700">Ordenar</label>
                <x-ui.selector id="orden-distribuidoras" wire:model.live="orden" ancho="auto" class="py-1.5">
                    @foreach ($ordenes as $valor => $texto)
                        <option value="{{ $valor }}">{{ $texto }}</option>
                    @endforeach
                </x-ui.selector>
            </div>

            <div class="flex items-center gap-3">
                @if ($filtrando)
                    <button type="button" wire:click="limpiarFiltros" class="text-sm font-medium fp-enlace" data-limpiar-filtros>
                        Limpiar filtros
                    </button>
                @endif

                {{-- Tarjetas o lista: la lista cabe más en el celular y se lee como directorio. --}}
                <div class="inline-flex rounded-xl border border-slate-200 bg-white p-0.5 shadow-sm" role="group" aria-label="Cómo ver las distribuidoras">
                    @foreach (['tarjetas' => 'Tarjetas', 'lista' => 'Lista'] as $valor => $texto)
                        <button type="button" wire:click="cambiarVista('{{ $valor }}')" aria-pressed="{{ $vistaActual === $valor ? 'true' : 'false' }}" data-vista="{{ $valor }}"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 {{ $vistaActual === $valor ? 'bg-fp-sidebar text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                @if ($valor === 'tarjetas')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z" />
                                @else
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" />
                                @endif
                            </svg>
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

    </div>

    @if ($distribuidoras->isEmpty() && $filtrando)
        <div class="fp-aparecer fp-tarjeta p-10 text-center text-slate-500">
            Ninguna distribuidora coincide con esa búsqueda.
            <button type="button" wire:click="limpiarFiltros" class="mt-3 block w-full text-sm font-medium fp-enlace sm:mx-auto sm:w-auto">
                Ver todas las distribuidoras
            </button>
        </div>
    @elseif ($distribuidoras->isEmpty())
        <div class="fp-aparecer fp-tarjeta p-10 text-center text-slate-500">
            No hay distribuidoras visibles en el marketplace por ahora.
        </div>
    @elseif ($vistaActual === 'lista')
        {{-- TG-391: vista de lista. En el celular cada fila se apila; en
             escritorio queda logo · datos · líneas · botón en una sola fila. --}}
        <ul class="fp-tarjeta divide-y divide-slate-100 overflow-hidden fp-escalonado" data-vista-lista>
            @foreach ($distribuidoras as $d)
                @php($urlTienda = $enlaceTienda->url($d))
                @php($suyas = $lineasDe[$d->id] ?? [])
                @php($direccionMapa = \App\Support\MapaUbicacion::direccion($d))
                <li wire:key="lista-{{ $d->id }}" class="flex flex-col gap-3 p-4 transition-colors duration-200 hover:bg-fp-page/60 sm:flex-row sm:items-center sm:gap-5 sm:p-5">
                    <div class="flex min-w-0 flex-1 items-center gap-4">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white shadow-sm ring-1 ring-slate-200">
                            @if ($d->logotipo_url)
                                <img src="{{ $d->logotipo_url }}" alt="Logo {{ $d->nombre_comercial }}" loading="lazy" decoding="async" width="56" height="56" class="h-full w-full object-contain p-1.5">
                            @else
                                <span class="text-lg font-semibold text-fp-sidebar" aria-hidden="true">{{ mb_strtoupper(mb_substr($d->nombre_comercial, 0, 1)) }}</span>
                            @endif
                        </span>
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-slate-900">
                                @if ($urlTienda)
                                    <a href="{{ $urlTienda }}" class="hover:underline">{{ $d->nombre_comercial }}</a>
                                @else
                                    {{ $d->nombre_comercial }}
                                @endif
                            </h3>
                            @if ($direccionMapa)
                                <p class="truncate text-sm text-slate-600">📍 {{ $direccionMapa }}</p>
                            @endif
                            @if ($d->telefono_publico)
                                <p class="text-sm"><a href="tel:{{ $d->telefono_publico }}" class="fp-enlace">{{ $d->telefono_publico }}</a></p>
                            @endif
                        </div>
                    </div>

                    @if ($suyas !== [])
                        <ul class="flex flex-wrap items-center gap-1.5 sm:max-w-sm sm:justify-end" aria-label="Líneas que vende {{ $d->nombre_comercial }}">
                            @foreach (array_slice($suyas, 0, $porTarjeta) as $l)
                                <li class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-0.5 pl-0.5 pr-2.5 text-xs font-medium text-slate-700">
                                    <x-ui.avatar-linea :nombre="$l->nombre" :url="$l->logotipo_url" tamano="sm" />
                                    {{ $l->nombre }}
                                </li>
                            @endforeach
                            @if (count($suyas) > $porTarjeta)
                                <li class="text-xs font-medium text-fp-text-muted">+{{ count($suyas) - $porTarjeta }}</li>
                            @endif
                        </ul>
                    @endif

                    @if ($urlTienda)
                        <a href="{{ $urlTienda }}" aria-label="Ver la tienda de {{ $d->nombre_comercial }}"
                           class="fp-btn fp-btn-oscuro w-full shrink-0 rounded-xl py-2 sm:w-auto">
                            Ver tienda
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 fp-escalonado">
            @foreach ($distribuidoras as $d)
                @php($urlTienda = $enlaceTienda->url($d))
                @php($suyas = $lineasDe[$d->id] ?? [])
                <article wire:key="dist-{{ $d->id }}" class="group fp-tarjeta-interactiva overflow-hidden flex flex-col">
                    <{{ $urlTienda ? 'a' : 'div' }} @if ($urlTienda) href="{{ $urlTienda }}" aria-label="Ver la tienda de {{ $d->nombre_comercial }}" @endif
                        class="flex h-40 items-center justify-center bg-fp-page">
                        <span class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full bg-white shadow-md ring-1 ring-slate-200 transition duration-200 group-hover:scale-105 group-hover:ring-fp-primary/40 motion-reduce:transform-none">
                            @if ($d->logotipo_url)
                                <img src="{{ $d->logotipo_url }}"
                                     alt="Logo {{ $d->nombre_comercial }}" loading="lazy" decoding="async" width="112" height="112"
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

                        {{-- TG-391: lo que vende, con el logo de cada línea (A24/A26).
                             Cada una lleva a la tienda ya filtrada por esa línea. --}}
                        @if ($suyas !== [])
                            <div class="mt-4" data-lineas-distribuidora>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-fp-text-muted">Líneas que vende</p>
                                <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach (array_slice($suyas, 0, $porTarjeta) as $l)
                                        <li>
                                            <{{ $urlTienda ? 'a' : 'span' }} @if ($urlTienda) href="{{ $urlTienda }}?linea={{ $l->id }}" @endif
                                                class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-0.5 pl-0.5 pr-2.5 text-xs font-medium text-slate-700 {{ $urlTienda ? 'fp-chip hover:border-fp-primary/40 hover:text-fp-primary' : '' }}">
                                                <x-ui.avatar-linea :nombre="$l->nombre" :url="$l->logotipo_url" tamano="sm" />
                                                {{ $l->nombre }}
                                            </{{ $urlTienda ? 'a' : 'span' }}>
                                        </li>
                                    @endforeach
                                    @if (count($suyas) > $porTarjeta)
                                        <li class="self-center text-xs font-medium text-fp-text-muted">+{{ count($suyas) - $porTarjeta }} más</li>
                                    @endif
                                </ul>
                            </div>
                        @endif

                        <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                            @php($direccionMapa = \App\Support\MapaUbicacion::direccion($d))
                            @if ($direccionMapa)
                                <li class="flex gap-2">
                                    <span class="text-slate-500 shrink-0">📍</span>
                                    <span>
                                        {{ $direccionMapa }}
                                        <a href="{{ \App\Support\MapaUbicacion::urlVerEnMapa($d) }}" target="_blank" rel="noopener" class="ml-1 whitespace-nowrap fp-enlace">Ver en mapa</a>
                                    </span>
                                </li>
                            @endif

                            @if ($d->telefono_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-500 shrink-0">📞</span>
                                    <a href="tel:{{ $d->telefono_publico }}" class="fp-enlace">
                                        {{ $d->telefono_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->email_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-500 shrink-0">✉️</span>
                                    <a href="mailto:{{ $d->email_publico }}" class="fp-enlace">
                                        {{ $d->email_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->horario_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-500 shrink-0">🕐</span>
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
