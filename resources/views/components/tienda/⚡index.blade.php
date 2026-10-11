<?php

use App\Services\Tienda\TiendaPublica;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| TG-233 (G14) — Tienda pública de una distribuidora (/tienda/{slug}).
|
| Pública: sin sesión. Qué productos y con qué precio lo decide TiendaPublica
| (CatalogoVisible + el precio de menudeo de PrecioEfectivo). Los filtros van
| en la dirección (?marca=, ?linea=, ?q=) y se guardan como texto para que una
| dirección mal escrita no truene: lo que no es un número se trata como
| "todas".
*/
new class extends Component {
    use WithPagination;

    #[Locked]
    public string $slug = '';

    #[Url(as: 'marca', except: '')]
    public string $marca = '';

    #[Url(as: 'linea', except: '')]
    public string $linea = '';

    #[Url(as: 'q', except: '')]
    public string $busqueda = '';

    public function mount(string $slug): void
    {
        // 404 si no existe o no está activa.
        app(TiendaPublica::class)->distribuidora($slug);

        $this->slug = $slug;
    }

    public function filtrarMarca(?int $marcaId = null): void
    {
        $this->marca = $marcaId ? (string) $marcaId : '';
        $this->resetPage();
    }

    public function filtrarLinea(?int $lineaId = null): void
    {
        $this->linea = $lineaId ? (string) $lineaId : '';
        $this->resetPage();
    }

    public function updatedBusqueda(): void
    {
        $this->resetPage();
    }

    public function limpiarFiltros(): void
    {
        $this->marca = '';
        $this->linea = '';
        $this->busqueda = '';
        $this->resetPage();
    }

    private function entero(string $valor): ?int
    {
        return ctype_digit($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    public function render()
    {
        $tienda = app(TiendaPublica::class);
        $distribuidora = $tienda->distribuidora($this->slug);

        $marcaId = $this->entero($this->marca);
        $lineaId = $this->entero($this->linea);
        $busqueda = trim(mb_substr($this->busqueda, 0, 100));
        $filtrando = $marcaId !== null || $lineaId !== null || $busqueda !== '';

        return $this->view([
            'distribuidora' => $distribuidora,
            'productos'     => $tienda->catalogo($distribuidora, [
                'marca'    => $marcaId,
                'linea'    => $lineaId,
                'busqueda' => $busqueda,
            ]),
            'destacados'    => $filtrando ? collect() : $tienda->destacados($distribuidora),
            'marcas'        => $tienda->marcas($distribuidora),
            'lineas'        => $tienda->lineas($distribuidora),
            'marcaId'       => $marcaId,
            'lineaId'       => $lineaId,
            'filtrando'     => $filtrando,
        ])
            ->layout('layouts.tienda', ['distribuidora' => $distribuidora])
            ->title($distribuidora->nombre_comercial.' — Tienda | FootwearPoint');
    }
};
?>

<div class="space-y-8">
    {{-- Datos de la distribuidora --}}
    <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 flex flex-col md:flex-row gap-6">
        <div class="flex-1">
            <h2 class="text-2xl font-bold text-slate-900">{{ $distribuidora->nombre_comercial }}</h2>
            @if ($distribuidora->descripcion_publica)
                <p class="text-sm text-slate-600 mt-2">{{ $distribuidora->descripcion_publica }}</p>
            @endif
            <p class="text-sm text-slate-600 mt-4">
                ¿Te gustó algo? Pídelo en mostrador o desde la app de FootwearPoint.
            </p>
        </div>

        <ul class="md:w-72 space-y-1.5 text-sm text-slate-600">
            @if ($distribuidora->direccion_publica)
                <li class="flex gap-2">
                    <span class="text-slate-400 shrink-0">📍</span>
                    <span>{{ $distribuidora->direccion_publica }}</span>
                </li>
            @endif
            @if ($distribuidora->telefono_publico)
                <li class="flex gap-2">
                    <span class="text-slate-400 shrink-0">📞</span>
                    <a href="tel:{{ $distribuidora->telefono_publico }}" class="text-[#2563EB] hover:underline">
                        {{ $distribuidora->telefono_publico }}
                    </a>
                </li>
            @endif
            @if ($distribuidora->email_publico)
                <li class="flex gap-2">
                    <span class="text-slate-400 shrink-0">✉️</span>
                    <a href="mailto:{{ $distribuidora->email_publico }}" class="text-[#2563EB] hover:underline">
                        {{ $distribuidora->email_publico }}
                    </a>
                </li>
            @endif
            @if ($distribuidora->horario_publico)
                <li class="flex gap-2">
                    <span class="text-slate-400 shrink-0">🕐</span>
                    <span>{{ $distribuidora->horario_publico }}</span>
                </li>
            @endif
        </ul>
    </section>

    {{-- Destacados (solo si la distribuidora eligió alguno y no se está filtrando) --}}
    @if ($destacados->isNotEmpty())
        <section aria-labelledby="titulo-destacados">
            <h2 id="titulo-destacados" class="text-xl font-bold text-slate-900 mb-4">Destacados</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach ($destacados as $producto)
                    <div wire:key="destacado-{{ $producto['id'] }}">
                        <x-tienda.tarjeta-producto :producto="$producto" :distribuidora="$distribuidora" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Marcas --}}
    @if ($marcas->isNotEmpty())
        <section aria-labelledby="titulo-marcas">
            <h2 id="titulo-marcas" class="text-xl font-bold text-slate-900 mb-4">Marcas</h2>
            <div class="flex flex-wrap gap-2" aria-label="Filtrar por marca">
                <button type="button" wire:click="filtrarMarca"
                    class="px-3 py-1.5 rounded-full text-sm {{ $marcaId === null ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                    Todas
                </button>
                @foreach ($marcas as $marca)
                    <button type="button" wire:click="filtrarMarca({{ $marca['id'] }})"
                        class="px-3 py-1.5 rounded-full text-sm {{ $marcaId === $marca['id'] ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                        {{ $marca['nombre'] }}
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Catálogo --}}
    <section aria-labelledby="titulo-catalogo">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-4">
            <div>
                <h2 id="titulo-catalogo" class="text-xl font-bold text-slate-900">Catálogo</h2>
                <p class="text-sm text-slate-500 mt-1">Lo que puedes pedir en {{ $distribuidora->nombre_comercial }}.</p>
            </div>
            <input type="search" wire:model.live.debounce.400ms="busqueda" maxlength="100"
                placeholder="Buscar por nombre, modelo, código o marca" aria-label="Buscar productos"
                class="w-full md:w-80 rounded-lg border-slate-300 text-sm">
        </div>

        @if ($lineas->count() > 1)
            <div class="mb-6 flex flex-wrap gap-2" aria-label="Filtrar por línea">
                <button type="button" wire:click="filtrarLinea"
                    class="px-3 py-1.5 rounded-full text-sm {{ $lineaId === null ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                    Todas las líneas
                </button>
                @foreach ($lineas as $linea)
                    <button type="button" wire:click="filtrarLinea({{ $linea['id'] }})"
                        class="px-3 py-1.5 rounded-full text-sm {{ $lineaId === $linea['id'] ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                        {{ $linea['nombre'] }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($productos->isEmpty())
            <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
                @if ($filtrando)
                    No encontramos productos con esos filtros.
                    <button type="button" wire:click="limpiarFiltros" class="block mx-auto mt-3 text-sm text-[#2563EB] hover:underline">
                        Ver todo el catálogo
                    </button>
                @else
                    Esta tienda todavía no tiene productos para mostrar.
                @endif
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach ($productos as $producto)
                    <div wire:key="producto-{{ $producto['id'] }}">
                        <x-tienda.tarjeta-producto :producto="$producto" :distribuidora="$distribuidora" />
                    </div>
                @endforeach
            </div>

            {{-- Paginación propia, en español (la de Livewire sale en inglés). --}}
            @if ($productos->hasPages())
                <nav class="mt-6 flex items-center justify-between text-sm" aria-label="Páginas del catálogo">
                    <button type="button" wire:click="previousPage" @disabled($productos->onFirstPage())
                        class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                        Anterior
                    </button>
                    <span class="text-slate-500">Página {{ $productos->currentPage() }} de {{ $productos->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $productos->hasMorePages())
                        class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                        Siguiente
                    </button>
                </nav>
            @endif
        @endif
    </section>
</div>
