<?php

use App\Models\Notificacion;
use App\Support\Services\AgruparNotificaciones;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('notificaciones-actualizadas')]
    public function refrescar(): void
    {
        // Re-render unread badge.
    }

    public function getNoLeidasCountProperty(): int
    {
        if (! Auth::check()) {
            return 0;
        }

        return (int) Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->whereNull('leida_at')
            ->count();
    }

    /** TG-196: grupos estilo Mercado Libre para el panel de la campana. */
    public function getGruposProperty()
    {
        if (! Auth::check()) {
            return collect();
        }

        return app(AgruparNotificaciones::class)->resumen((int) Auth::id());
    }
};
?>

<div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false" @keydown.escape.window="abierto = false">
    <button type="button" @click="abierto = !abierto" :aria-expanded="abierto.toString()" aria-haspopup="true"
        data-campana
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-full text-fp-sidebar/70 transition hover:bg-fp-page hover:text-fp-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 {{ request()->routeIs('notificaciones.*') ? 'bg-fp-page text-fp-primary ring-1 ring-fp-primary/20' : '' }}"
        @if ($this->noLeidasCount > 0)
            aria-label="Notificaciones ({{ $this->noLeidasCount }} sin leer)"
        @else
            aria-label="Notificaciones"
        @endif
    >
        <x-notificaciones.icono-grupo icono="campana" />
        @if ($this->noLeidasCount > 0)
            <span class="absolute -right-0.5 -top-0.5 inline-flex min-w-[1.15rem] items-center justify-center rounded-full bg-fp-danger px-1 py-0.5 text-[10px] font-semibold leading-none text-white ring-2 ring-white shadow-sm">
                {{ $this->noLeidasCount > 99 ? '99+' : $this->noLeidasCount }}
            </span>
        @endif
    </button>

    <div x-show="abierto" x-cloak x-transition.opacity.scale.origin.top.right
        class="absolute right-0 z-40 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-lg ring-1 ring-black/5"
        role="dialog" aria-label="Notificaciones">
        <div class="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-fp-sidebar to-fp-accent px-4 py-3">
            <p class="text-sm font-semibold text-white">Notificaciones</p>
            <a href="{{ route('notificaciones.index') }}" wire:navigate class="text-xs font-medium text-white/70 hover:text-white">Ver todas</a>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach ($this->grupos as $g)
                <li>
                    <a href="{{ route('notificaciones.index', ['grupo' => $g['clave']]) }}" wire:navigate
                        class="flex items-center gap-3 px-4 py-3 transition hover:bg-fp-page" data-grupo="{{ $g['clave'] }}">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-fp-badge-info-bg text-fp-primary">
                            <x-notificaciones.icono-grupo :icono="$g['icono']" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-fp-sidebar">{{ $g['titulo'] }}</span>
                            <span class="block truncate text-xs text-fp-text-muted">{{ $g['vista_previa'] ?? 'Sin notificaciones' }}</span>
                        </span>
                        @if ($g['no_leidas'] > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-fp-danger px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                                {{ $g['no_leidas'] > 99 ? '99+' : $g['no_leidas'] }}
                            </span>
                        @endif
                        <svg class="h-4 w-4 shrink-0 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</div>
