<?php

use App\Models\Pedido;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Pedidos — FootwearPoint')] class extends Component {
    public string $filtro_estado = '';
    public string $filtro_tipo = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function getPedidosProperty()
    {
        $query = Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->orderByDesc('id');

        if ($this->filtro_estado !== '') {
            $query->where('estado', $this->filtro_estado);
        }

        if ($this->filtro_tipo !== '') {
            $query->where('tipo', $this->filtro_tipo);
        }

        return $query->limit(100)->get();
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Pedidos</h2>
            <p class="text-sm text-slate-500 mt-1">Listado de pedidos de la distribuidora</p>
        </div>

        <a href="{{ route('pedidos.create') }}"
           class="inline-flex items-center justify-center rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2 hover:bg-blue-700 shrink-0">
            Nuevo pedido
        </a>
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="filtro_estado"
                class="rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
            <option value="">Todos los estados</option>
            <option value="borrador">Borrador</option>
            <option value="colocado">Colocado</option>
            <option value="confirmado">Confirmado</option>
            <option value="en_transito">En tránsito</option>
            <option value="entregado">Entregado</option>
            <option value="rechazado">Rechazado</option>
        </select>

        <select wire:model.live="filtro_tipo"
                class="rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
            <option value="">Todos los tipos</option>
            <option value="cliente_directo">Cliente directo</option>
            <option value="revendedor">Revendedor</option>
        </select>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Folio</th>
                    <th class="px-4 py-3 font-medium">Tipo</th>
                    <th class="px-4 py-3 font-medium">Propietario</th>
                    <th class="px-4 py-3 font-medium">Estado</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->pedidos as $p)
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $p->folio }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $p->tipo === 'cliente_directo' ? 'Cliente' : 'Revendedor' }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $p->clienteDirecto?->nombre
                                ?? $p->revendedorAfiliacion?->revendedor?->nombre
                                ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.insignia-estado :estado="$p->estado" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            ${{ number_format((float) $p->total, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pedidos.show', $p->id) }}"
                               class="text-[#2563EB] hover:underline text-sm">
                                Ver
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            No hay pedidos.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
