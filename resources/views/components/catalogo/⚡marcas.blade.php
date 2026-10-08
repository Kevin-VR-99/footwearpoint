<?php

use App\Models\Marca;
use Livewire\Component;

/**
 * Marcas del catálogo, de solo lectura (TG-213).
 *
 * El catálogo es uno solo para todo FootwearPoint y lo administra el admin
 * general: la distribuidora lo consulta, pero no lo edita. Lo que sí es suyo
 * (qué líneas vende, su precio de mayoreo y qué productos oculta) va en sus
 * propias pantallas.
 */
new class extends Component {
    public function getMarcasProperty()
    {
        return Marca::query()->orderBy('nombre')->get();
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-6 py-3">Marca</th>
                    <th class="px-6 py-3">Descripción</th>
                    <th class="px-6 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->marcas as $marca)
                    <tr>
                        <td class="px-6 py-3.5 font-medium text-slate-900">
                            <div class="flex items-center gap-3">
                                @if ($marca->logotipo_url)
                                    <img src="{{ $marca->logotipo_url }}" alt="{{ $marca->nombre }}"
                                        class="h-8 w-8 rounded object-cover">
                                @endif
                                {{ $marca->nombre }}
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-slate-600">{{ $marca->descripcion ?? '—' }}</td>
                        <td class="px-6 py-3.5">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $marca->activa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $marca->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-8 text-center text-fp-text-muted">
                            Todavía no hay marcas en el catálogo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
