<?php

use App\Models\Notificacion;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Notificaciones — FootwearPoint')] class extends Component
{
    public string $filtro = 'todas'; // todas | no_leidas
    public string $mensaje = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function getNotificacionesProperty()
    {
        $query = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->orderByDesc('created_at');

        if ($this->filtro === 'no_leidas') {
            $query->whereNull('leida_at');
        }

        return $query->limit(50)->get();
    }

    public function getNoLeidasCountProperty()
    {
        return Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->whereNull('leida_at')
            ->count();
    }

    public function marcarLeida(int $id)
    {
        $n = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->where('id', $id)
            ->first();

        if ($n && $n->leida_at === null) {
            $n->leida_at = now();
            $n->save();
            $this->mensaje = 'Marcada como leída.';
        }
    }

    public function marcarTodasLeidas()
    {
        Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->whereNull('leida_at')
            ->update(['leida_at' => now()]);

        $this->mensaje = 'Todas marcadas como leídas.';
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Notificaciones</h2>
            <p class="text-sm text-slate-500 mt-1">
                Avisos internos del panel
                @if ($this->noLeidasCount > 0)
                    <span class="ml-1 inline-flex items-center rounded-full bg-red-100 text-red-700 px-2 py-0.5 text-xs font-medium">
                        {{ $this->noLeidasCount }} sin leer
                    </span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="filtro"
                    class="rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
                <option value="todas">Todas</option>
                <option value="no_leidas">Solo no leídas</option>
            </select>
            @if ($this->noLeidasCount > 0)
                <button type="button" wire:click="marcarTodasLeidas"
                        class="text-sm text-[#2563EB] hover:underline">
                    Marcar todas leídas
                </button>
            @endif
        </div>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">
            {{ $mensaje }}
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm divide-y divide-slate-100">
        @forelse ($this->notificaciones as $n)
            <div class="px-5 py-4 flex gap-3 {{ $n->leida_at ? 'bg-white' : 'bg-blue-50/40' }}">
                <div class="mt-1 shrink-0">
                    @if ($n->leida_at)
                        <span class="block w-2 h-2 rounded-full bg-slate-300"></span>
                    @else
                        <span class="block w-2 h-2 rounded-full bg-[#2563EB]"></span>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $n->titulo }}</h3>
                        <span class="text-xs text-slate-400">
                            {{ optional($n->created_at)->format('d/m/Y H:i') }}
                        </span>
                    </div>
                    <p class="text-sm text-slate-600 mt-1">{{ $n->mensaje }}</p>
                    @if (! $n->leida_at)
                        <button type="button" wire:click="marcarLeida({{ $n->id }})"
                                class="mt-2 text-xs text-[#2563EB] hover:underline">
                            Marcar como leída
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-5 py-10 text-center text-slate-500 text-sm">
                No tienes notificaciones.
            </div>
        @endforelse
    </div>
</div>