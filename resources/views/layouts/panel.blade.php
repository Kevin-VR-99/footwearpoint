<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Panel - FootwearPoint' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
</head>

<body class="min-h-screen bg-fp-page text-slate-800 antialiased" x-data="{
    sidebarOpen: false,
    userMenuOpen: false,
    openCatalogo: {{ request()->routeIs('distribuidora.catalogo', 'catalogo.*') ? 'true' : 'false' }},
    openOperacion: {{ request()->routeIs('stock.*', 'punto-venta.*', 'ciclo.*', 'pedidos.*', 'vales.*') ? 'true' : 'false' }},
    openMas: {{ request()->routeIs('reportes.*', 'auditoria.*') ? 'true' : 'false' }},
    openSistema: {{ request()->routeIs('distribuidora.configuracion') ? 'true' : 'false' }},
}"
    @keydown.escape.window="sidebarOpen = false; userMenuOpen = false">
    @php
        $esAdminDistribuidora = false;
        $logotipoDistribuidora = null;
        if (auth()->check()) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(\App\Support\Tenant::id() ?? 0);
            $esAdminDistribuidora = auth()->user()->hasRole('admin_distribuidora');
            $tenantId = \App\Support\Tenant::id();
            if ($tenantId) {
                $logotipoDistribuidora = \App\Models\Distribuidora::query()->whereKey($tenantId)->value('logotipo_url');
            }
        }
        $nombreUsuario = auth()->user()->nombre ?? '';
        $emailUsuario = auth()->user()->email ?? '';
        $iniciales =
            collect(preg_split('/\s+/', trim($nombreUsuario)))
                ->filter()
                ->take(2)
                ->map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                ->implode('') ?:
            'FP';
    @endphp

    <div class="min-h-screen flex">
        {{-- Sidebar deslizable (drawer en todos los anchos) --}}
        <aside
            class="sticky top-0 z-40 flex h-screen shrink-0 flex-col overflow-hidden bg-gradient-to-b from-fp-sidebar via-fp-sidebar to-fp-accent text-white shadow-xl shadow-fp-sidebar/30 transition-[width] duration-200 ease-out"
            :class="sidebarOpen ? 'w-64' : 'w-0'" :aria-hidden="(!sidebarOpen).toString()">
            <div class="flex h-full w-64 flex-col">
                <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>

                {{-- Brand + cerrar --}}
                <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4">
                    <a href="{{ route('dashboard') }}" wire:navigate class="group flex min-w-0 items-center gap-2.5">
                        <span
                            class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 ring-2 ring-fp-primary/35 transition group-hover:ring-fp-primary/60">
                            <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt=""
                                class="h-9 w-9 rounded-full object-cover" width="36" height="36">
                        </span>
                        <span class="min-w-0 leading-tight">
                            <span class="block truncate text-sm font-semibold tracking-tight text-white">Footwear
                                Point</span>
                            <span
                                class="block text-[10px] font-medium uppercase tracking-[0.14em] text-white/45">Distribuidora</span>
                        </span>
                    </a>
                    <button type="button"
                        class="rounded-lg p-1.5 text-white/60 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/50"
                        @click="sidebarOpen = false" aria-label="Cerrar menú">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto p-3">
                    {{-- Inicio --}}
                    <a href="{{ route('dashboard') }}" wire:navigate
                        class="group flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('dashboard') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                        <span
                            class="flex h-7 w-7 items-center justify-center rounded-lg {{ request()->routeIs('dashboard') ? 'bg-fp-primary text-white' : 'bg-white/10 text-white/70 group-hover:bg-white/15 group-hover:text-white' }}">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 10.5L12 4l9 6.5V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z" />
                            </svg>
                        </span>
                        Inicio
                    </a>

                    {{-- Catálogo (admin) --}}
                    @if ($esAdminDistribuidora)
                        <div class="pt-2">
                            <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/35">
                                Gestión</p>
                            <button type="button"
                                class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                                @click="openCatalogo = !openCatalogo" :aria-expanded="openCatalogo.toString()">
                                <span class="flex items-center gap-2.5 font-medium text-white/90">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M4 6h16M4 12h16M4 18h10" />
                                        </svg>
                                    </span>
                                    Catálogo
                                </span>
                                <svg class="h-4 w-4 text-white/45 transition-transform"
                                    :class="openCatalogo && 'rotate-180'" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="openCatalogo" x-cloak
                                class="ml-3 mt-0.5 space-y-0.5 border-l border-white/10 pl-2">
                                <a href="{{ route('distribuidora.catalogo') }}" wire:navigate
                                    class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('distribuidora.catalogo', 'catalogo.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                                    Temporadas y productos
                                </a>
                            </div>
                        </div>
                    @endif

                    {{-- Operación --}}
                    <div class="pt-2">
                        <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/35">
                            Operación</p>
                        <button type="button"
                            class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                            @click="openOperacion = !openOperacion" :aria-expanded="openOperacion.toString()">
                            <span class="flex items-center gap-2.5 font-medium text-white/90">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M3 7h18M5 7l1.5 12h11L19 7M9 7V5a3 3 0 016 0v2" />
                                    </svg>
                                </span>
                                Menú
                            </span>
                            <svg class="h-4 w-4 text-white/45 transition-transform"
                                :class="openOperacion && 'rotate-180'" xmlns="http://www.w3.org/2000/svg"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="openOperacion" x-cloak
                            class="ml-3 mt-0.5 space-y-0.5 border-l border-white/10 pl-2">
                            <a href="{{ route('stock.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('stock.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Stock</a>
                            <a href="{{ route('punto-venta.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('punto-venta.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Punto
                                de Venta</a>
                            <a href="{{ route('ciclo.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('ciclo.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Ciclo
                                de compra</a>
                            <a href="{{ route('pedidos.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('pedidos.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Pedidos</a>
                            <a href="{{ route('vales.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('vales.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Vales</a>
                        </div>
                    </div>

                    {{-- Más --}}
                    <div class="pt-2">
                        <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/35">Más
                        </p>
                        <button type="button"
                            class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                            @click="openMas = !openMas" :aria-expanded="openMas.toString()">
                            <span class="flex items-center gap-2.5 font-medium text-white/90">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12M6 12h12" />
                                    </svg>
                                </span>
                                Extra
                            </span>
                            <svg class="h-4 w-4 text-white/45 transition-transform" :class="openMas && 'rotate-180'"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="openMas" x-cloak class="ml-3 mt-0.5 space-y-0.5 border-l border-white/10 pl-2">
                            <a href="{{ route('reportes.index') }}" wire:navigate
                                class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('reportes.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Reportes</a>
                            @if ($esAdminDistribuidora)
                                <a href="{{ route('auditoria.index') }}" wire:navigate
                                    class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('auditoria.*') ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">Auditoría</a>
                            @endif
                        </div>
                    </div>

                    {{-- Sistema (admin) --}}
                    @if ($esAdminDistribuidora)
                        <div class="pt-2">
                            <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/35">
                                Sistema</p>
                            <button type="button"
                                class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                                @click="openSistema = !openSistema" :aria-expanded="openSistema.toString()">
                                <span class="flex items-center gap-2.5 font-medium text-white/90">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 15.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9c0 .7.4 1.3 1 1.5H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z" />
                                        </svg>
                                    </span>
                                    Sistema
                                </span>
                                <svg class="h-4 w-4 text-white/45 transition-transform"
                                    :class="openSistema && 'rotate-180'" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="openSistema" x-cloak
                                class="ml-3 mt-0.5 space-y-0.5 border-l border-white/10 pl-2">
                                <a href="{{ route('distribuidora.configuracion') }}" wire:navigate
                                    class="block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs('distribuidora.configuracion') ? 'bg-white/15 font-medium text-white ring-1 ring-fp-danger/40' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                                    <span class="flex items-center justify-between gap-2">
                                        <span>Configuración</span>
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-fp-danger"></span>
                                    </span>
                                </a>
                            </div>
                        </div>
                    @endif
                </nav>

                <div class="border-t border-white/10 px-4 py-3">
                    <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-white/35">Footwear Point</p>
                    <p class="mt-0.5 text-[11px] text-white/50">Panel operativo</p>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-200 ease-out">
            {{-- Top bar: logo+nombre (abre sidebar) + usuario --}}
            <header
                class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/85">
                <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
                <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                    <button x-show="!sidebarOpen" x-cloak x-transition.opacity type="button"
                        class="group flex items-center gap-2.5 rounded-xl py-1 pr-2.5 pl-1 text-left transition hover:bg-fp-page focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40"
                        @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()"
                        aria-label="Abrir o cerrar menú">
                        <span
                            class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-fp-sidebar shadow-sm ring-2 ring-fp-primary/20 transition group-hover:ring-fp-primary/40">
                            <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt=""
                                class="h-8 w-8 rounded-full object-cover" width="32" height="32">
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold tracking-tight text-fp-sidebar">Footwear
                                Point</span>
                            <span
                                class="hidden text-[10px] font-medium uppercase tracking-[0.14em] text-fp-text-muted sm:block">Panel</span>
                        </span>
                    </button>

                    <div class="flex-1"></div>

                    <div class="flex items-center gap-1.5 sm:gap-2">
                        <livewire:notificaciones.nav-badge />

                        {{-- Menú usuario --}}
                        <div class="relative" @click.outside="userMenuOpen = false">
                            <button type="button"
                                class="flex items-center gap-2 rounded-full border border-slate-200/90 bg-white py-1 pl-1 pr-2.5 text-left shadow-sm transition hover:border-fp-primary/30 hover:bg-fp-page focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40"
                                @click="userMenuOpen = !userMenuOpen" :aria-expanded="userMenuOpen.toString()">
                                @if ($logotipoDistribuidora)
                                    <img src="{{ $logotipoDistribuidora }}" alt="Logotipo"
                                        class="h-8 w-8 rounded-full object-cover bg-slate-100 ring-2 ring-white"
                                        width="32" height="32">
                                @else
                                    <span
                                        class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-fp-sidebar to-fp-accent text-[11px] font-semibold text-white ring-2 ring-fp-primary/15">
                                        {{ $iniciales }}
                                    </span>
                                @endif
                                <span class="hidden min-w-0 max-w-[10rem] sm:block">
                                    <span
                                        class="block truncate text-sm font-medium text-fp-sidebar">{{ $nombreUsuario }}</span>
                                </span>
                                <svg class="h-4 w-4 text-slate-400 transition-transform"
                                    :class="userMenuOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <div x-show="userMenuOpen" x-cloak x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute right-0 z-40 mt-2 w-60 origin-top-right overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-lg ring-1 ring-black/5"
                                role="menu">
                                <div
                                    class="border-b border-slate-100 bg-gradient-to-r from-fp-sidebar to-fp-accent px-3.5 py-3">
                                    <p class="truncate text-sm font-semibold text-white">{{ $nombreUsuario }}</p>
                                    @if ($emailUsuario !== '')
                                        <p class="truncate text-xs text-white/70">{{ $emailUsuario }}</p>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('logout') }}" class="p-1.5">
                                    @csrf
                                    <button type="submit"
                                        class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-fp-danger transition hover:bg-fp-danger-soft"
                                        role="menuitem">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none"
                                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                            aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                        </svg>
                                        Cerrar sesión
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6 overflow-auto">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui.toast-global />

    @livewireScripts
</body>

</html>
