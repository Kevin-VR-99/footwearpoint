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
    public function getCampanasProperty()
    {
        return Campana::query()
            ->with('linea')
            ->orderBy('estado')
            ->orderByDesc('fecha_inicio')
            ->get();
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
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
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $campana->estado === 'activa' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
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
