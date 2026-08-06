<?php

use App\Services\Reporte\ResumenOperativoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Reportes — FootwearPoint')] class extends Component
{
    public string $desde = '';
    public string $hasta = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function getResumenProperty(): array
    {
        return app(ResumenOperativoAction::class)->ejecutar(
            $this->desde !== '' ? $this->desde : null,
            $this->hasta !== '' ? $this->hasta : null,
        );
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

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
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
                    <th class="px-4 py-2 text-right">Monto</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->resumen['pedidos']['por_estado'] as $fila)
                    <tr>
                        <td class="px-4 py-3">
                            <x-ui.insignia-estado :estado="$fila['estado']" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            ${{ number_format($fila['monto'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-slate-500">Sin datos</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>