<?php

use App\Models\CategoriaProducto;
use Livewire\Component;

/** Categorías del catálogo compartido, de solo lectura (TG-213). */
new class extends Component {
    public function getCategoriasProperty()
    {
        return CategoriaProducto::query()->orderBy('nombre')->get();
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    <div class="overflow-hidden fp-tarjeta fp-aparecer">
        <table class="fp-tabla min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-6 py-3">Categoría</th>
                    <th class="px-6 py-3">Descripción</th>
                    <th class="px-6 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->categorias as $categoria)
                    <tr>
                        <td class="px-6 py-3.5 font-medium text-slate-900">{{ $categoria->nombre }}</td>
                        <td class="px-6 py-3.5 text-slate-600">{{ $categoria->descripcion ?? '—' }}</td>
                        <td class="px-6 py-3.5">
                            <span class=\"fp-insignia {{ $categoria->activa ? 'fp-insignia-success' : 'fp-insignia-neutral' }}\">
                                {{ $categoria->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-8 text-center text-fp-text-muted">
                            Todavía no hay categorías en el catálogo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
