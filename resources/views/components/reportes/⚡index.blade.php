<?php

use App\Services\Reporte\ResumenOperativoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Reportes — FootwearPoint')] class extends Component {
    public string $desde = '';
    public string $hasta = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function getResumenProperty(): array
    {
        return app(ResumenOperativoAction::class)->ejecutar($this->desde !== '' ? $this->desde : null, $this->hasta !== '' ? $this->hasta : null);
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-slate-900">Reportes</h2>
        <p class="text-sm text-slate-500 mt-1">Resumen operativo de la distribuidora</p>
    </div>

    <div class="mb-6 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Desde</label>
            <input type="date" wire:model.live="desde" class="rounded-lg border-slate-300 text-sm" />
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Hasta</label>
            <input type="date" wire:model.live="hasta" class="rounded-lg border-slate-300 text-sm" />
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mb-8">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Pedidos</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['pedidos']['total'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                ${{ number_format($this->resumen['pedidos']['monto_total'], 2) }}
            </p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Ventas directas</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['ventas_directas']['total'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                ${{ number_format($this->resumen['ventas_directas']['monto_total'], 2) }}
            </p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Vales activos</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['vales']['activos'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                Saldo ${{ number_format($this->resumen['vales']['saldo_activo'], 2) }}
            </p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">Pedidos por estado</div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2 text-right">Cantidad</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->resumen['pedidos']['por_estado'] as $fila)
                    <tr>
                        <td class="px-4 py-3">
                            <x-ui.insignia-estado :estado="$fila['estado']" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-4 py-8 text-center text-slate-500">Sin datos</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">
            Detalle de pedidos
            <span class="text-xs font-normal text-slate-500">(máx. 100 del periodo)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Folio</th>
                        <th class="px-4 py-2">Quién</th>
                        <th class="px-4 py-2">Fecha</th>
                        <th class="px-4 py-2">Estado</th>
                        <th class="px-4 py-2">Descripción</th>
                        <th class="px-4 py-2 text-right">Total</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->resumen['pedidos']['lista'] ?? [] as $pedido)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium text-slate-800 whitespace-nowrap">
                                {{ $pedido['folio'] ?? '#' . $pedido['id'] }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-slate-800">{{ $pedido['quien'] }}</div>
                                <div class="text-xs text-slate-400">{{ $pedido['tipo'] }}</div>
                                @if (!empty($pedido['capturado_por']))
                                    <div class="text-xs text-slate-400">Capturó: {{ $pedido['capturado_por'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                                {{ $pedido['fecha'] ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.insignia-estado :estado="$pedido['estado']" />
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-xs">
                                <span class="line-clamp-3" title="{{ $pedido['descripcion'] }}">
                                    {{ $pedido['descripcion'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                ${{ number_format($pedido['total'], 2) }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('pedidos.show', $pedido['id']) }}"
                                    class="text-fp-primary text-xs font-medium hover:underline">
                                    Ver pedido
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                                No hay pedidos en el periodo.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
