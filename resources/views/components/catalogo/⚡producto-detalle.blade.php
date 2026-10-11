<?php

use App\Models\Producto;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalle de un producto del catálogo compartido, de solo lectura (TG-213).
 *
 * Muestra lo que esta distribuidora vende de ese producto: en qué temporadas
 * suyas está, con qué precios (menudeo del catálogo y su mayoreo) y qué tallas
 * tiene disponibles.
 */
new #[Layout('layouts.panel')] #[Title('Producto — FootwearPoint')] class extends Component {
    public int $productoId;

    public function mount(int $producto): void
    {
        $this->productoId = $producto;
    }

    public function getProductoProperty(): Producto
    {
        return Producto::with(['marca', 'categoria', 'variantes.talla', 'variantes.color'])
            ->findOrFail($this->productoId);
    }

    /** Las publicaciones de este producto que esta distribuidora vende. */
    public function getPublicacionesProperty()
    {
        $publicaciones = app(CatalogoVisible::class)->consulta()
            ->where('producto_id', $this->productoId)
            ->with(['campana.linea', 'imagenes', 'disponibilidadPorVariante.variante.talla', 'disponibilidadPorVariante.variante.color'])
            ->get();

        app(PrecioEfectivo::class)->precargar($publicaciones);

        return $publicaciones;
    }

    public function getPreciosProperty(): PrecioEfectivo
    {
        return app(PrecioEfectivo::class);
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('distribuidora.catalogo') }}" class="text-sm text-fp-primary hover:underline">← Catálogo</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-fp-sidebar">{{ $this->producto->nombre }}</h1>
        <p class="mt-1 text-sm text-fp-text-muted">
            Modelo {{ $this->producto->modelo }}
            @if ($this->producto->marca) · {{ $this->producto->marca->nombre }} @endif
            @if ($this->producto->categoria) · {{ $this->producto->categoria->nombre }} @endif
        </p>
    </div>

    <x-catalogo.aviso-solo-lectura />

    {{-- En qué temporadas lo vende --}}
    @forelse ($this->publicaciones as $pc)
        <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                        {{ $pc->campana?->linea?->nombre }}
                    </p>
                    <p class="font-semibold text-slate-900">{{ $pc->campana?->nombre }}</p>
                    <p class="text-xs text-fp-text-muted">Código {{ $pc->codigo_catalogo }}</p>
                </div>
                <div class="text-right text-sm">
                    <p class="text-fp-text-muted">Menudeo: <span class="tabular-nums">${{ number_format($this->precios->menudeo($pc), 2) }}</span></p>
                    <p class="font-semibold text-fp-primary">Tu mayoreo: <span class="tabular-nums">${{ number_format($this->precios->mayoreo($pc), 2) }}</span></p>
                </div>
            </div>

            @if ($pc->imagenes->isNotEmpty())
                <div class="flex flex-wrap gap-3 border-b border-slate-100 px-6 py-4">
                    @foreach ($pc->imagenes->sortByDesc('es_principal') as $imagen)
                        <img src="{{ $imagen->url }}" alt="{{ $this->producto->nombre }}"
                            class="h-20 w-20 rounded object-cover {{ $imagen->es_principal ? 'ring-2 ring-fp-primary' : '' }}">
                    @endforeach
                </div>
            @endif

            <div class="px-6 py-4">
                <p class="mb-2 text-sm font-semibold text-slate-900">Tallas y disponibilidad</p>
                <div class="flex flex-wrap gap-2">
                    @forelse ($pc->disponibilidadPorVariante as $disponibilidad)
                        @php($estado = $disponibilidad->estado)
                        <span class="rounded-lg border px-3 py-1.5 text-xs font-semibold
                            {{ $estado === 'disponible' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : '' }}
                            {{ $estado === 'bajo_pedido' ? 'border-amber-300 bg-amber-50 text-amber-900' : '' }}
                            {{ $estado === 'no_disponible' ? 'border-slate-300 bg-slate-100 text-slate-500 line-through' : '' }}">
                            {{ $disponibilidad->variante?->talla?->valor }}
                            · {{ $disponibilidad->variante?->nombre_color_comercial ?? $disponibilidad->variante?->color?->nombre }}
                        </span>
                    @empty
                        <p class="text-sm text-fp-text-muted">Esta temporada todavía no tiene tallas registradas.</p>
                    @endforelse
                </div>
            </div>
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200/80 bg-white px-6 py-8 text-center text-fp-text-muted shadow-sm">
            Tu distribuidora no está vendiendo este producto: puede ser de una línea que no tienes activa,
            de una temporada que no está activa, o lo tienes oculto.
        </div>
    @endforelse

    {{-- Todas sus tallas en el catálogo --}}
    <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-6 py-4">
            <p class="font-semibold text-slate-900">Tallas del producto en el catálogo</p>
        </div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-6 py-3">SKU</th>
                    <th class="px-6 py-3">Talla</th>
                    <th class="px-6 py-3">Color</th>
                    <th class="px-6 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->producto->variantes as $variante)
                    <tr>
                        <td class="px-6 py-3 font-medium text-slate-900">{{ $variante->sku }}</td>
                        <td class="px-6 py-3 text-slate-600">{{ $variante->talla?->valor }}</td>
                        <td class="px-6 py-3 text-slate-600">
                            {{ $variante->nombre_color_comercial ?? $variante->color?->nombre }}
                        </td>
                        <td class="px-6 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $variante->activa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $variante->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-fp-text-muted">
                            Este producto todavía no tiene tallas registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
