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

<div class="space-y-8">
    {{-- Encabezado --}}
    <div class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <div class="absolute inset-y-0 left-0 w-1.5 bg-fp-primary"></div>
        <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-fp-danger/10"></div>
        <div class="absolute -right-2 top-10 h-16 w-16 rounded-full bg-fp-primary/10"></div>
        <div class="relative flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Panel distribuidora</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-fp-sidebar">Inicio</h2>
                <p class="mt-1 text-sm text-fp-text-muted">
                    Hola, {{ Auth::user()->name }}. Resumen operativo de la distribuidora.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->resumen['notif_sin_leer'] > 0)
                    <a href="{{ route('notificaciones.index') }}"
                       wire:navigate
                       class="inline-flex items-center gap-2 rounded-full border border-fp-danger/20 bg-fp-danger-soft px-3 py-1.5 text-xs font-medium text-fp-danger transition hover:border-fp-danger/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-fp-danger"></span>
                        {{ $this->resumen['notif_sin_leer'] }} sin leer
                    </a>
                @endif
                <span class="inline-flex items-center rounded-full bg-fp-page px-3 py-1.5 text-xs font-medium text-fp-sidebar ring-1 ring-slate-200/80">
                    {{ now()->translatedFormat('D d M Y') }}
                </span>
            </div>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-fp-primary/35 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-fp-text-muted">Pedidos totales</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-fp-sidebar">{{ $this->resumen['pedidos_total'] }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-fp-primary/10 text-fp-primary">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5h6M8 9h8M7 13h10M6 17h12" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-3/4 rounded-full bg-fp-primary"></div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-fp-text-muted">Borradores</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-fp-sidebar">{{ $this->resumen['pedidos_borrador'] }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" />
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-[11px] text-fp-text-muted">Pendientes de colocar</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-fp-primary/35 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-fp-text-muted">Colocados</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-fp-sidebar">{{ $this->resumen['pedidos_colocados'] }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-fp-badge-info-bg text-fp-badge-info-fg">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-[11px] text-fp-text-muted">En flujo operativo</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-fp-danger/30 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-medium text-fp-text-muted">Vales activos</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-fp-sidebar">{{ $this->resumen['vales_activos'] }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-fp-danger-soft text-fp-danger">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5h10a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z" />
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-[11px] font-semibold text-fp-danger">
                Saldo ${{ number_format($this->resumen['vales_saldo'], 2) }}
            </p>
        </div>
    </div>

    {{-- Acciones --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <a href="{{ route('pedidos.create') }}"
           wire:navigate
           class="group relative overflow-hidden rounded-2xl bg-fp-primary p-5 text-white shadow-sm transition hover:bg-fp-accent hover:shadow-md">
            <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-white/10"></div>
            <div class="relative flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-white/70">Acción</p>
                    <p class="mt-1 text-lg font-semibold">Nuevo pedido</p>
                    <p class="mt-1 text-sm text-white/80">Captura con catálogo por línea</p>
                </div>
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-full bg-white/15 transition group-hover:bg-white/25">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                    </svg>
                </span>
            </div>
        </a>

        <a href="{{ route('vales.index') }}"
           wire:navigate
           class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:border-fp-danger/35 hover:shadow-md">
            <div class="absolute inset-y-0 left-0 w-1 bg-fp-danger"></div>
            <div class="relative flex items-start justify-between gap-3 pl-2">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-fp-danger">Vales</p>
                    <p class="mt-1 text-lg font-semibold text-fp-sidebar">Gestionar vales</p>
                    <p class="mt-1 text-sm text-fp-text-muted">Emitir y consultar saldo</p>
                </div>
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-full bg-fp-danger-soft text-fp-danger transition group-hover:bg-fp-danger group-hover:text-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </span>
            </div>
        </a>
    </div>

    {{-- Pedidos recientes --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 bg-gradient-to-r from-fp-sidebar to-fp-accent px-5 py-3.5">
            <div>
                <h3 class="text-sm font-semibold text-white">Pedidos recientes</h3>
                <p class="text-[11px] text-white/65">Últimos movimientos de la distribuidora</p>
            </div>
            <a href="{{ route('pedidos.index') }}"
               wire:navigate
               class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white transition hover:bg-white/20">
                Ver todos
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page/80 text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Folio</th>
                        <th class="px-5 py-3 font-semibold">Propietario</th>
                        <th class="px-5 py-3 font-semibold">Estado</th>
                        <th class="px-5 py-3 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->pedidosRecientes as $p)
                        <tr class="transition hover:bg-fp-page/70">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('pedidos.show', $p->id) }}"
                                   wire:navigate
                                   class="font-medium text-fp-primary hover:underline">
                                    {{ $p->folio }}
                                </a>
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">
                                {{ $p->clienteDirecto?->nombre
                                    ?? $p->revendedorAfiliacion?->revendedor?->nombre
                                    ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <x-ui.insignia-estado :estado="$p->estado" />
                            </td>
                            <td class="px-5 py-3.5 text-right font-medium tabular-nums text-fp-sidebar">
                                ${{ number_format((float) $p->total, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center">
                                <p class="text-sm font-medium text-fp-sidebar">Sin pedidos aún</p>
                                <p class="mt-1 text-xs text-fp-text-muted">Crea el primero desde Nuevo pedido.</p>
                                <a href="{{ route('pedidos.create') }}"
                                   wire:navigate
                                   class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                    Nuevo pedido
                                </a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>