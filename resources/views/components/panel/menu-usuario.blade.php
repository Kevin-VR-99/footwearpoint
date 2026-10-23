{{-- TG-234: menú de usuario del encabezado. Lo comparten el panel de distribuidora y el admin general. --}}
@props(['logotipo' => null, 'subtitulo' => null])
@php
    $nombreUsuario = auth()->user()?->nombreVisible() ?? '';
    $emailUsuario = auth()->user()?->email ?? '';
    $iniciales =
        collect(preg_split('/\s+/', trim($nombreUsuario)))
            ->filter()
            ->take(2)
            ->map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->implode('') ?:
        'FP';
@endphp
<div class="relative" x-data="{ userMenuOpen: false }" @click.outside="userMenuOpen = false"
    @keydown.escape.window="userMenuOpen = false" data-menu-usuario>
    <button type="button"
        class="flex items-center gap-2 rounded-full border border-slate-200/90 bg-white py-1 pl-1 pr-2.5 text-left shadow-sm transition hover:border-fp-primary/30 hover:bg-fp-page focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40"
        @click="userMenuOpen = !userMenuOpen" :aria-expanded="userMenuOpen.toString()" aria-haspopup="menu">
        @if ($logotipo)
            <img src="{{ $logotipo }}" alt="Logotipo" decoding="async"
                class="h-8 w-8 rounded-full object-cover bg-slate-100 ring-2 ring-white" width="32" height="32">
        @else
            <span
                class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-fp-sidebar to-fp-accent text-[11px] font-semibold text-white ring-2 ring-fp-primary/15">
                {{ $iniciales }}
            </span>
        @endif
        <span class="hidden min-w-0 max-w-[10rem] sm:block">
            <span class="block truncate text-sm font-medium text-fp-sidebar">{{ $nombreUsuario }}</span>
        </span>
        <svg class="h-4 w-4 text-slate-400 transition-transform" :class="userMenuOpen && 'rotate-180'"
            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
            aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div x-show="userMenuOpen" x-cloak x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 z-40 mt-2 w-60 origin-top-right overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-lg ring-1 ring-black/5"
        role="menu">
        <div class="border-b border-slate-100 bg-gradient-to-r from-fp-sidebar to-fp-accent px-3.5 py-3">
            <p class="truncate text-sm font-semibold text-white">{{ $nombreUsuario }}</p>
            @if ($emailUsuario !== '')
                <p class="truncate text-xs text-white/70">{{ $emailUsuario }}</p>
            @endif
            @if ($subtitulo)
                <p class="mt-1 text-[10px] font-medium uppercase tracking-[0.14em] text-white/50">{{ $subtitulo }}</p>
            @endif
        </div>
        @if (isset($slot) && trim((string) $slot) !== '')
            <div class="border-b border-slate-100 p-1.5">
                {{ $slot }}
            </div>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="p-1.5">
            @csrf
            <button type="submit"
                class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-fp-danger transition hover:bg-fp-danger-soft hover:text-fp-badge-danger-fg"
                role="menuitem">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                Cerrar sesión
            </button>
        </form>
    </div>
</div>
