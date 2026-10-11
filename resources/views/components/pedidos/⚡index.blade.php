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
    <x-panel.encabezado titulo="Pedidos" subtitulo="Listado de pedidos de la distribuidora" class="mb-6">
        <x-slot:acciones>
            <x-panel.boton :href="route('pedidos.create')" class="shrink-0">
                Nuevo pedido
            </x-panel.boton>
        </x-slot:acciones>
    </x-panel.encabezado>

    <x-panel.filtros class="mb-4">
        <select wire:model.live="filtro_estado"
                class="rounded-lg border-slate-300 text-sm focus:border-fp-primary focus:ring-fp-primary">
            <option value="">Todos los estados</option>
            <option value="borrador">Borrador</option>
            <option value="descartado">Descartado</option>
            <option value="colocado">Colocado</option>
            <option value="en_revision">En revisión</option>
            <option value="confirmado">Confirmado</option>
            <option value="incluido_en_ciclo">En ciclo</option>
            <option value="solicitado_fabrica">Solicitado fábrica</option>
            <option value="en_transito">En tránsito</option>
            <option value="recibido_distribuidora">Recibido</option>
            <option value="parcialmente_disponible">Parc. disponible</option>
            <option value="listo_entrega">Listo entrega</option>
            <option value="vencido_recoleccion">Vencido recolección</option>
            <option value="no_surtido">No surtido</option>
            <option value="entregado">Entregado</option>
            <option value="rechazado">Rechazado</option>
            <option value="cancelado">Cancelado</option>
        </select>

        <select wire:model.live="filtro_tipo"
                class="rounded-lg border-slate-300 text-sm focus:border-fp-primary focus:ring-fp-primary">
            <option value="">Todos los tipos</option>
            <option value="cliente_directo">Cliente directo</option>
            <option value="revendedor">Cliente mayorista</option>
        </select>
    </x-panel.filtros>

    <x-panel.tabla>
        <x-slot:encabezado>
                <tr>
                    <th class="px-4 py-3 font-medium">Folio</th>
                    <th class="px-4 py-3 font-medium">Tipo</th>
                    <th class="px-4 py-3 font-medium">Propietario</th>
                    <th class="px-4 py-3 font-medium">Estado</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
        </x-slot:encabezado>
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
                               class="text-fp-primary hover:underline text-sm">
                                Ver
                            </a>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="6" mensaje="No hay pedidos." />
                @endforelse
    </x-panel.tabla>
</div>
