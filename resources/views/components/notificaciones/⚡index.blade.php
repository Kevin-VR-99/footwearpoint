<?php

use App\Models\Notificacion;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Notificaciones - FootwearPoint')] class extends Component
{
    public string  = 'todas'; // todas | no_leidas
    public string  = '';

    public function mount()
    {
        if (! Auth::check()) {
            return ->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function getNotificacionesProperty()
    {
         = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->orderByDesc('created_at');

        if (->filtro === 'no_leidas') {
            ->whereNull('leida_at');
        }

        return ->limit(50)->get();
    }

    public function getNoLeidasCountProperty()
    {
        return Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->whereNull('leida_at')
            ->count();
    }

    public function marcarLeida(int )
    {
         = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->where('id', )
            ->first();

        if ( && ->leida_at === null) {
            ->leida_at = now();
            ->save();
            ->mensaje = 'Marcada como leída.';
            ->dispatch('notificaciones-actualizadas');
        }
    }

    public function marcarTodasLeidas()
    {
        Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->whereNull('leida_at')
            ->update(['leida_at' => now()]);

        ->mensaje = 'Todas marcadas como leídas.';
        ->dispatch('notificaciones-actualizadas');
    }
};
?>

<div class="mx-auto max-w-3xl">
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Centro de avisos</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">Notificaciones</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if (->noLeidasCount > 0)
                    {{ ->noLeidasCount }} sin leer
                @else
                    Todo al día
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm">
                <button type="button" wire:click="('filtro', 'todas')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-slate-900 text-white shadow-sm' =>  === 'todas',
                        'text-slate-600 hover:text-slate-900' =>  !== 'todas',
                    ])>Todas</button>
                <button type="button" wire:click="('filtro', 'no_leidas')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-slate-900 text-white shadow-sm' =>  === 'no_leidas',
                        'text-slate-600 hover:text-slate-900' =>  !== 'no_leidas',
                    ])>Sin leer</button>
            </div>
            @if (->noLeidasCount > 0)
                <button type="button" wire:click="marcarTodasLeidas"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    Marcar todas leídas
                </button>
            @endif
        </div>
    </div>

    @if ()
        <div class="mb-4 rounded-lg border border-emerald-200/80 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{  }}
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        @forelse (->notificaciones as )
            <div @class([
                'flex gap-4 border-b border-slate-100 px-5 py-4 last:border-0',
                'bg-slate-50/70' => ! ->leida_at,
                'bg-white' => ->leida_at,
            ])>
                <div class="mt-1.5 shrink-0">
                    <span @class([
                        'block h-2 w-2 rounded-full',
                        'bg-blue-600' => ! ->leida_at,
                        'bg-slate-200' => ->leida_at,
                    ])></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h2 class="text-sm font-semibold text-slate-900">{{ ->titulo }}</h2>
                        <time class="text-xs tabular-nums text-slate-400">{{ optional(->created_at)->format('d/m/Y H:i') }}</time>
                    </div>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ ->mensaje }}</p>
                    @if (! ->leida_at)
                        <button type="button" wire:click="marcarLeida({{ ->id }})"
                            class="mt-3 text-xs font-medium text-blue-700 hover:underline">
                            Marcar como leída
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0m6 0H9" />
                    </svg>
                </div>
                <p class="text-sm font-medium text-slate-800">Sin notificaciones</p>
                <p class="mt-1 text-sm text-slate-500">Cuando haya avisos nuevos aparecerán aquí.</p>
            </div>
        @endforelse
    </div>
</div>
