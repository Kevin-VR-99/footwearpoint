<?php

use App\Models\Campana;
use Livewire\Component;

/**
 * Temporadas del catálogo compartido, de solo lectura (TG-213).
 *
 * Cada temporada pertenece a una línea (D1) y solo una puede estar activa por
 * línea (D7). Las administra el admin general.
 */
new class extends Component {
    public string $buscar = '';

    public function getCampanasProperty()
    {
        return Campana::query()
            ->with('linea')
            ->when(trim($this->buscar) !== '', function ($q) {
                $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($this->buscar)).'%';
                $q->where(fn ($w) => $w->where('nombre', 'like', $t)
                    ->orWhereHas('linea', fn ($l) => $l->where('nombre', 'like', $t)));
            })
            ->orderBy('estado')
            ->orderByDesc('fecha_inicio')
            ->get();
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Buscar temporada o línea…" etiqueta="Buscar temporadas" class="w-full sm:w-80" data-buscador-temporadas />

    <div class="overflow-hidden fp-tarjeta fp-aparecer">
        <table class="fp-tabla min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <th class="px-6 py-3">Temporada</th>
                    <th class="px-6 py-3">Línea</th>
                    <th class="px-6 py-3">Vigencia</th>
                    <th class="px-6 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->campanas as $campana)
                    <tr>
                        <td class="px-6 py-3.5 font-medium text-slate-900">{{ $campana->nombre }}</td>
                        <td class="px-6 py-3.5 text-slate-600">{{ $campana->linea?->nombre ?? '—' }}</td>
                        <td class="px-6 py-3.5 text-slate-600">
                            {{ $campana->fecha_inicio?->format('d/m/Y') ?? '—' }}
                            —
                            {{ $campana->fecha_fin?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="px-6 py-3.5">
                            <span class=\"fp-insignia {{ $campana->estado === 'activa' ? 'fp-insignia-success' : 'fp-insignia-neutral' }}\">
                                {{ ucfirst(str_replace('_', ' ', $campana->estado)) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-fp-text-muted">
                            Todavía no hay temporadas en el catálogo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
