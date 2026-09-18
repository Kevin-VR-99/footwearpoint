<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panel - FootwearPoint' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>

<body
    class="min-h-screen bg-fp-page text-slate-800 antialiased"
    x-data="{
        sidebarOpen: false,
        userMenuOpen: false,
        openCatalogo: {{ request()->routeIs('distribuidora.catalogo', 'catalogo.*') ? 'true' : 'false' }},
        openOperacion: {{ request()->routeIs('stock.*', 'punto-venta.*', 'ciclo.*', 'pedidos.*', 'vales.*') ? 'true' : 'false' }},
        openMas: {{ request()->routeIs('notificaciones.*', 'reportes.*', 'auditoria.*') ? 'true' : 'false' }},
        openSistema: {{ request()->routeIs('distribuidora.configuracion') ? 'true' : 'false' }},
    }"
    @keydown.escape.window="sidebarOpen = false; userMenuOpen = false"
>
    @php
        $esAdminDistribuidora = false;
        $logotipoDistribuidora = null;
        if (auth()->check()) {
            app(\Spatie\Permission\PermissionRegistrar::class)
                ->setPermissionsTeamId(\App\Support\Tenant::id() ?? 0);
            $esAdminDistribuidora = auth()->user()->hasRole('admin_distribuidora');
            $tenantId = \App\Support\Tenant::id();
            if ($tenantId) {
                $logotipoDistribuidora = \App\Models\Distribuidora::query()
                    ->whereKey($tenantId)
                    ->value('logotipo_url');
            }
        }
        $nombreUsuario = auth()->user()->nombre ?? '';
        $emailUsuario = auth()->user()->email ?? '';
        $iniciales = collect(preg_split('/\s+/', trim($nombreUsuario)))
            ->filter()
            ->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->implode('') ?: 'FP';
    @endphp

    <div class="min-h-screen flex">
        {{-- Backdrop móvil --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="sidebarOpen = false"
            aria-hidden="true"
        ></div>

        {{-- Sidebar --}}
        <aside
            class="fixed inset-y-0 left-0 z-50 w-64 bg-fp-sidebar text-white flex flex-col shrink-0 transform transition-transform duration-200 ease-out lg:static lg:translate-x-0 lg:z-auto"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            {{-- Brand --}}
            <div class="px-4 py-4 border-b border-white/10 flex items-center justify-between gap-3">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 min-w-0" @click="sidebarOpen = false">
                    <img
                        src="{{ asset('brand/logo-mark-white-40.png') }}"
                        alt=""
                        class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-white/20"
                        width="40"
                        height="40"
                    >
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold tracking-wide text-white">Footwear Point</span>
                    </span>
                </a>
                <button
                    type="button"
                    class="lg:hidden rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white"
                    @click="sidebarOpen = false"
                    aria-label="Cerrar menú"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
                {{-- Inicio --}}
                <a href="{{ route('dashboard') }}"
                    @click="sidebarOpen = false"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'bg-white/15 font-medium' : '' }}">
                    Inicio
                </a>

                {{-- Catálogo (admin) --}}
                @if ($esAdminDistribuidora)
                    <div class="pt-1">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-white/80 hover:bg-white/10"
                            @click="openCatalogo = !openCatalogo"
                            :aria-expanded="openCatalogo.toString()"
                        >
                            <span class="font-medium text-white/90">Catálogo</span>
                            <svg class="h-4 w-4 text-white/50 transition-transform" :class="openCatalogo && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="openCatalogo" x-cloak class="mt-0.5 space-y-0.5 border-l border-white/10 ml-3 pl-2">
                            <a href="{{ route('distribuidora.catalogo') }}"
                                @click="sidebarOpen = false"
                                class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('distribuidora.catalogo', 'catalogo.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                                Temporadas y productos
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Operación --}}
                <div class="pt-1">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-white/80 hover:bg-white/10"
                        @click="openOperacion = !openOperacion"
                        :aria-expanded="openOperacion.toString()"
                    >
                        <span class="font-medium text-white/90">Operación</span>
                        <svg class="h-4 w-4 text-white/50 transition-transform" :class="openOperacion && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <div x-show="openOperacion" x-cloak class="mt-0.5 space-y-0.5 border-l border-white/10 ml-3 pl-2">
                        <a href="{{ route('stock.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('stock.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Stock
                        </a>
                        <a href="{{ route('punto-venta.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('punto-venta.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Punto de Venta
                        </a>
                        <a href="{{ route('ciclo.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('ciclo.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Ciclo de compra
                        </a>
                        <a href="{{ route('pedidos.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('pedidos.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Pedidos
                        </a>
                        <a href="{{ route('vales.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('vales.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Vales
                        </a>
                    </div>
                </div>

                {{-- Más --}}
                <div class="pt-1">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-white/80 hover:bg-white/10"
                        @click="openMas = !openMas"
                        :aria-expanded="openMas.toString()"
                    >
                        <span class="font-medium text-white/90">Más</span>
                        <svg class="h-4 w-4 text-white/50 transition-transform" :class="openMas && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <div x-show="openMas" x-cloak class="mt-0.5 space-y-0.5 border-l border-white/10 ml-3 pl-2">
                        <div @click="sidebarOpen = false">
                            <livewire:notificaciones.nav-badge />
                        </div>
                        <a href="{{ route('reportes.index') }}"
                            @click="sidebarOpen = false"
                            class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('reportes.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                            Reportes
                        </a>
                        @if ($esAdminDistribuidora)
                            <a href="{{ route('auditoria.index') }}"
                                @click="sidebarOpen = false"
                                class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('auditoria.*') ? 'bg-white/15 font-medium' : 'text-white/80' }}">
                                Auditoría
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Sistema (admin) --}}
                @if ($esAdminDistribuidora)
                    <div class="pt-1">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-white/80 hover:bg-white/10"
                            @click="openSistema = !openSistema"
                            :aria-expanded="openSistema.toString()"
                        >
                            <span class="font-medium text-white/90">Sistema</span>
                            <svg class="h-4 w-4 text-white/50 transition-transform" :class="openSistema && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="openSistema" x-cloak class="mt-0.5 space-y-0.5 border-l border-white/10 ml-3 pl-2">
                            <a href="{{ route('distribuidora.configuracion') }}"
                                @click="sidebarOpen = false"
                                class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('distribuidora.configuracion') ? 'bg-white/15 font-medium ring-1 ring-fp-danger/40' : 'text-white/80' }}">
                                <span class="flex items-center justify-between gap-2">
                                    <span>Configuración</span>
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-fp-danger"></span>
                                </span>
                            </a>
                        </div>
                    </div>
                @endif
            </nav>


        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            {{-- Top bar: menú + usuario --}}
            <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200/80 bg-white/95 px-4 py-2.5 backdrop-blur supports-[backdrop-filter]:bg-white/80">
                <button
                    type="button"
                    class="lg:hidden rounded-md p-2 text-slate-700 hover:bg-slate-100"
                    @click="sidebarOpen = true"
                    aria-label="Abrir menú"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <div class="flex flex-1 items-center gap-2 min-w-0 lg:hidden">
                    <img
                        src="{{ asset('brand/logo-mark-white-40.png') }}"
                        alt=""
                        class="h-8 w-8 shrink-0 rounded-full object-cover"
                        width="32"
                        height="32"
                    >
                    <span class="truncate text-sm font-semibold text-slate-900">Footwear Point</span>
                </div>

                <div class="hidden lg:block flex-1"></div>

                {{-- Menú usuario (arriba derecha) --}}
                <div class="relative" @click.outside="userMenuOpen = false">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-1 pr-2.5 text-left shadow-sm hover:border-slate-300 hover:bg-slate-50"
                        @click="userMenuOpen = !userMenuOpen"
                        :aria-expanded="userMenuOpen.toString()"
                    >
                        @if ($logotipoDistribuidora)
                            <img
                                src="{{ $logotipoDistribuidora }}"
                                alt="Logotipo"
                                class="h-8 w-8 rounded-full object-cover bg-slate-100"
                                width="32"
                                height="32"
                            >
                        @else
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-fp-sidebar text-[11px] font-semibold text-white">
                                {{ $iniciales }}
                            </span>
                        @endif
                        <span class="hidden sm:block min-w-0 max-w-[10rem]">
                            <span class="block truncate text-sm font-medium text-slate-800">{{ $nombreUsuario }}</span>
                        </span>
                        <svg class="h-4 w-4 text-slate-400 transition-transform" :class="userMenuOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div
                        x-show="userMenuOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-black/5"
                        role="menu"
                    >
                        <div class="border-b border-slate-100 px-3 py-2.5">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $nombreUsuario }}</p>
                            @if ($emailUsuario !== '')
                                <p class="truncate text-xs text-slate-500">{{ $emailUsuario }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="px-1 py-1">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-fp-danger hover:bg-fp-danger-soft"
                                role="menuitem"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                Cerrar sesión
                            </button>
                        </form>
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