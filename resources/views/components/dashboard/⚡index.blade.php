<?php

use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Vale;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Inicio — FootwearPoint')] class extends Component
{
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
        $pedidosPorEstado = Pedido::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'pedidos_total'     => (int) Pedido::query()->count(),
            'pedidos_borrador'  => (int) ($pedidosPorEstado['borrador'] ?? 0),
            'pedidos_colocados' => (int) ($pedidosPorEstado['colocado'] ?? 0),
            'vales_activos'     => (int) Vale::query()->where('estado', 'activo')->count(),
            'vales_saldo'       => (float) Vale::query()->where('estado', 'activo')->sum('saldo_actual'),
            'notif_sin_leer'    => (int) Notificacion::query()
                ->where('usuario_id', Auth::id())
                ->whereNull('leida_at')
                ->count(),
        ];
    }

    public function getPedidosRecientesProperty()
    {
        return Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-slate-900">Inicio</h2>
        <p class="text-sm text-slate-500 mt-1">Resumen de la distribuidora</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Pedidos totales</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $this->resumen['pedidos_total'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Borradores</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $this->resumen['pedidos_borrador'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Colocados</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $this->resumen['pedidos_colocados'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Vales activos</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $this->resumen['vales_activos'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                Saldo ${{ number_format($this->resumen['vales_saldo'], 2) }}
            </p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3 mb-8">
        <a href="{{ route('pedidos.create') }}"
           class="block bg-white rounded-xl border border-slate-200 p-4 hover:border-[#2563EB] shadow-sm">
            <p class="font-medium text-slate-900">Nuevo pedido</p>
            <p class="text-sm text-slate-500 mt-1">Captura con catálogo</p>
        </a>
        <a href="{{ route('vales.index') }}"
           class="block bg-white rounded-xl border border-slate-200 p-4 hover:border-[#2563EB] shadow-sm">
            <p class="font-medium text-slate-900">Vales</p>
            <p class="text-sm text-slate-500 mt-1">Emitir y consultar</p>
        </a>
        <a href="{{ route('notificaciones.index') }}"
           class="block bg-white rounded-xl border border-slate-200 p-4 hover:border-[#2563EB] shadow-sm">
            <p class="font-medium text-slate-900">Notificaciones</p>
            <p class="text-sm text-slate-500 mt-1">
                {{ $this->resumen['notif_sin_leer'] }} sin leer
            </p>
        </a>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <span class="font-medium text-slate-800">Pedidos recientes</span>
            <a href="{{ route('pedidos.index') }}" class="text-sm text-[#2563EB] hover:underline">Ver todos</a>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Folio</th>
                    <th class="px-4 py-2 font-medium">Propietario</th>
                    <th class="px-4 py-2 font-medium">Estado</th>
                    <th class="px-4 py-2 font-medium text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->pedidosRecientes as $p)
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3">
                            <a href="{{ route('pedidos.show', $p->id) }}" class="text-[#2563EB] hover:underline font-medium">
                                {{ $p->folio }}
                            </a>
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">Sin pedidos aún</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>