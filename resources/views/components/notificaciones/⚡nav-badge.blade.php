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
};
?>

<a href="{{ route('notificaciones.index') }}"
    wire:navigate
    class="relative inline-flex h-9 w-9 items-center justify-center rounded-full text-fp-sidebar/70 transition hover:bg-fp-page hover:text-fp-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 {{ request()->routeIs('notificaciones.*') ? 'bg-fp-page text-fp-primary ring-1 ring-fp-primary/20' : '' }}"
    @if ($this->noLeidasCount > 0)
        aria-label="Notificaciones ({{ $this->noLeidasCount }} sin leer)"
    @else
        aria-label="Notificaciones"
    @endif
>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0m6 0H9" />
    </svg>
    @if ($this->noLeidasCount > 0)
        <span class="absolute -right-0.5 -top-0.5 inline-flex min-w-[1.15rem] items-center justify-center rounded-full bg-fp-danger px-1 py-0.5 text-[10px] font-semibold leading-none text-white ring-2 ring-white shadow-sm">
            {{ $this->noLeidasCount > 99 ? '99+' : $this->noLeidasCount }}
        </span>
    @endif
</a>