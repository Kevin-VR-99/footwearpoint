<?php

use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\PrecioEfectivo;
use Livewire\Component;

/**
 * Los productos que esta distribuidora vende del catálogo compartido, de solo
 * lectura (TG-213).
 *
 * Lo que se ve es exactamente lo que ven sus clientes: temporadas activas de
 * sus líneas, sin lo que ella misma ocultó. Los precios son los suyos: el
 * menudeo del catálogo y su propio mayoreo.
 */
new class extends Component {
    public string $busqueda = '';

    public function getPublicacionesProperty()
    {
        $publicaciones = app(CatalogoVisible::class)->consulta()
            ->with(['producto.marca', 'producto.categoria', 'campana.linea'])
            ->get()
            ->sortBy(fn ($pc) => $pc->producto?->nombre)
            ->values();

        app(PrecioEfectivo::class)->precargar($publicaciones);

        $termino = mb_strtolower(trim($this->busqueda));

        if ($termino === '') {
            return $publicaciones;
        }

        return $publicaciones->filter(function ($pc) use ($termino) {
            $texto = mb_strtolower(implode(' ', [
                $pc->producto?->nombre,
                $pc->producto?->modelo,
                $pc->codigo_catalogo,
                $pc->producto?->marca?->nombre,
            ]));

            return str_contains($texto, $termino);
        })->values();
    }

    public function getPreciosProperty(): PrecioEfectivo
    {
        return app(PrecioEfectivo::class);
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
        <input type="search" wire:model.live.debounce.400ms="busqueda"
            placeholder="Buscar por nombre, modelo, código o marca"
            class="w-full rounded-lg border-slate-300 text-sm">
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-6 py-3">Producto</th>
                    <th class="px-6 py-3">Marca</th>
                    <th class="px-6 py-3">Línea / temporada</th>
                    <th class="px-6 py-3">Código</th>
                    <th class="px-6 py-3 text-right">Menudeo</th>
                    <th class="px-6 py-3 text-right">Tu mayoreo</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->publicaciones as $pc)
                    <tr>
                        <td class="px-6 py-3.5">
                            <p class="font-medium text-slate-900">{{ $pc->producto?->nombre }}</p>
                            <p class="text-xs text-fp-text-muted">Modelo {{ $pc->producto?->modelo }}</p>
                        </td>
                        <td class="px-6 py-3.5 text-slate-600">{{ $pc->producto?->marca?->nombre ?? '—' }}</td>
                        <td class="px-6 py-3.5 text-slate-600">
                            {{ $pc->campana?->linea?->nombre ?? '—' }}
                            <span class="block text-xs text-fp-text-muted">{{ $pc->campana?->nombre }}</span>
                        </td>
                        <td class="px-6 py-3.5 text-slate-600">{{ $pc->codigo_catalogo }}</td>
                        <td class="px-6 py-3.5 text-right tabular-nums">${{ number_format($this->precios->menudeo($pc), 2) }}</td>
                        <td class="px-6 py-3.5 text-right tabular-nums font-semibold text-fp-primary">
                            ${{ number_format($this->precios->mayoreo($pc), 2) }}
                        </td>
                        <td class="px-6 py-3.5 text-right">
                            <a href="{{ route('catalogo.producto.detalle', $pc->producto_id) }}"
                                class="text-sm font-medium text-fp-primary hover:underline">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-fp-text-muted">
                            No hay productos a la venta. Revisa que tu distribuidora tenga líneas activas
                            y que esas líneas tengan una temporada activa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
