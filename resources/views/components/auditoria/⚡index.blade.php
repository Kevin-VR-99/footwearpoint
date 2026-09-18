<?php

use App\Models\Auditoria;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.panel')] #[Title('Auditoría — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $filtro_accion = '';
    public string $filtro_entidad = '';
    public string $filtro_desde = '';
    public string $filtro_hasta = '';
    public string $buscar = '';
    public ?int $detalleId = null;

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        if (! Auth::user()->hasRole('admin_distribuidora')) {
            abort(403, 'Solo el administrador de la distribuidora puede ver la auditoría.');
        }
    }

    public function updatingFiltroAccion(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroEntidad(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroDesde(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroHasta(): void
    {
        $this->resetPage();
    }

    public function updatingBuscar(): void
    {
        $this->resetPage();
    }

    public function limpiarFiltros(): void
    {
        $this->filtro_accion = '';
        $this->filtro_entidad = '';
        $this->filtro_desde = '';
        $this->filtro_hasta = '';
        $this->buscar = '';
        $this->resetPage();
    }

    public function toggleDetalle(int $id): void
    {
        $this->detalleId = $this->detalleId === $id ? null : $id;
    }

    public function getAccionesProperty()
    {
        return Auditoria::query()
            ->select('accion')
            ->distinct()
            ->orderBy('accion')
            ->pluck('accion');
    }

    public function getEntidadesProperty()
    {
        return Auditoria::query()
            ->select('entidad_tipo')
            ->distinct()
            ->orderBy('entidad_tipo')
            ->pluck('entidad_tipo');
    }

    public function getRegistrosProperty()
    {
        $query = Auditoria::query()
            ->with('usuario')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($this->filtro_accion !== '') {
            $query->where('accion', $this->filtro_accion);
        }

        if ($this->filtro_entidad !== '') {
            $query->where('entidad_tipo', $this->filtro_entidad);
        }

        if ($this->filtro_desde !== '') {
            $query->whereDate('created_at', '>=', $this->filtro_desde);
        }

        if ($this->filtro_hasta !== '') {
            $query->whereDate('created_at', '<=', $this->filtro_hasta);
        }

        if ($this->buscar !== '') {
            $termino = '%'.$this->buscar.'%';
            $query->where(function ($q) use ($termino) {
                $q->where('accion', 'like', $termino)
                    ->orWhere('entidad_tipo', 'like', $termino)
                    ->orWhere('ip_origen', 'like', $termino)
                    ->orWhereHas('usuario', fn ($u) => $u->where('nombre', 'like', $termino)
                        ->orWhere('email', 'like', $termino));
            });
        }

        return $query->paginate(20);
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-fp-primary">Seguridad</p>
            <h2 class="text-2xl font-bold text-slate-900">Auditoría</h2>
            <p class="mt-1 text-sm text-slate-500">
                Registro de operaciones sensibles de la distribuidora (E17-03).
            </p>
        </div>
        <span class="inline-flex items-center gap-1.5 self-start rounded-full border border-fp-danger/20 bg-fp-danger-soft px-2.5 py-1 text-[11px] font-medium text-fp-danger">
            <span class="h-1.5 w-1.5 rounded-full bg-fp-danger"></span>
            Solo admin
        </span>
    </div>

    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="min-w-[10rem] flex-1">
                <label class="mb-1 block text-xs text-slate-500">Buscar</label>
                <input type="search" wire:model.live.debounce.300ms="buscar"
                    placeholder="Usuario, acción, IP…"
                    class="w-full rounded-lg border-slate-300 text-sm focus:border-fp-primary focus:ring-fp-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">Acción</label>
                <select wire:model.live="filtro_accion" class="rounded-lg border-slate-300 text-sm">
                    <option value="">Todas</option>
                    @foreach ($this->acciones as $accion)
                        <option value="{{ $accion }}">{{ $accion }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">Entidad</label>
                <select wire:model.live="filtro_entidad" class="rounded-lg border-slate-300 text-sm">
                    <option value="">Todas</option>
                    @foreach ($this->entidades as $entidad)
                        <option value="{{ $entidad }}">{{ $entidad }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">Desde</label>
                <input type="date" wire:model.live="filtro_desde" class="rounded-lg border-slate-300 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">Hasta</label>
                <input type="date" wire:model.live="filtro_hasta" class="rounded-lg border-slate-300 text-sm" />
            </div>
            <button type="button" wire:click="limpiarFiltros"
                class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                Limpiar
            </button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Fecha</th>
                        <th class="px-4 py-3 font-medium">Usuario</th>
                        <th class="px-4 py-3 font-medium">Acción</th>
                        <th class="px-4 py-3 font-medium">Entidad</th>
                        <th class="px-4 py-3 font-medium">IP</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->registros as $row)
                        <tr class="hover:bg-slate-50/80" wire:key="aud-{{ $row->id }}">
                            <td class="px-4 py-3 whitespace-nowrap tabular-nums text-slate-700">
                                {{ optional($row->created_at)->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">{{ $row->usuario?->nombre ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ $row->usuario?->email }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-fp-primary">
                                    {{ $row->accion }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $row->entidad_tipo }}
                                @if ($row->entidad_id)
                                    <span class="text-slate-400">#{{ $row->entidad_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs tabular-nums text-slate-500">{{ $row->ip_origen ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="toggleDetalle({{ $row->id }})"
                                    class="text-xs font-medium text-fp-primary hover:underline">
                                    {{ $detalleId === $row->id ? 'Ocultar' : 'Detalle' }}
                                </button>
                            </td>
                        </tr>
                        @if ($detalleId === $row->id)
                            <tr class="bg-slate-50/60" wire:key="aud-det-{{ $row->id }}">
                                <td colspan="6" class="px-4 py-4">
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Datos previos</p>
                                            <pre class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">{{ $row->datos_previos ? json_encode($row->datos_previos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Datos nuevos</p>
                                            <pre class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">{{ $row->datos_nuevos ? json_encode($row->datos_nuevos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">
                                No hay registros de auditoría con estos filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->registros->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">
                {{ $this->registros->links() }}
            </div>
        @endif
    </div>
</div>
