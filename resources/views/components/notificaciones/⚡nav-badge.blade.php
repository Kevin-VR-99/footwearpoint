<?php

use App\Models\Notificacion;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('notificaciones-actualizadas')]
    public function refrescar(): void
    {
        // Re-render so the unread count updates after mark-read.
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
};
?>

<a href="{{ route('notificaciones.index') }}"
    @click="sidebarOpen = false"
    wire:navigate
    class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('notificaciones.*') ? 'bg-white/15' : '' }}">
    <span>Notificaciones</span>
    @if ($this->noLeidasCount > 0)
        <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
            {{ $this->noLeidasCount > 99 ? '99+' : $this->noLeidasCount }}
        </span>
    @endif
</a>
