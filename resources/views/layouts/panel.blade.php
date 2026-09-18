<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panel - FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>

<body class="min-h-screen bg-[#F5F6FA] text-slate-800 antialiased" x-data="{ sidebarOpen: false }">
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

        {{-- Sidebar / drawer --}}
        <aside
            class="fixed inset-y-0 left-0 z-50 w-64 bg-[#111E38] text-white flex flex-col shrink-0 transform transition-transform duration-200 ease-out lg:static lg:translate-x-0 lg:z-auto"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            @keydown.escape.window="sidebarOpen = false"
        >
            <div class="p-5 border-b border-white/10 flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-lg font-bold">FootwearPoint</h1>
                    <p class="text-xs text-white/60">Panel distribuidora</p>
                </div>
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
                <a href="{{ route('dashboard') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'bg-white/15' : '' }}">
                    Inicio
                </a>
                @if (auth()->user()?->hasRole('admin_distribuidora'))
                    <a href="{{ route('distribuidora.catalogo') }}"
                        @click="sidebarOpen = false"
                        class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('distribuidora.catalogo', 'catalogo.*') ? 'bg-white/15' : '' }}">
                        Catálogo
                    </a>
                @endif
                <a href="{{ route('stock.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('stock.*') ? 'bg-white/15' : '' }}">
                    Stock
                </a>
                <a href="{{ route('punto-venta.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('punto-venta.*') ? 'bg-white/15' : '' }}">
                    Punto de Venta
                </a>
                <a href="{{ route('ciclo.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('ciclo.*') ? 'bg-white/15' : '' }}">
                    Ciclo de compra
                </a>
                <a href="{{ route('pedidos.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('pedidos.*') ? 'bg-white/15' : '' }}">
                    Pedidos
                </a>
                <a href="{{ route('vales.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('vales.*') ? 'bg-white/15' : '' }}">
                    Vales
                </a>
                <a href="{{ route('notificaciones.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('notificaciones.*') ? 'bg-white/15' : '' }}">
                    Notificaciones
                </a>
                <a href="{{ route('reportes.index') }}"
                    @click="sidebarOpen = false"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('reportes.*') ? 'bg-white/15' : '' }}">
                    Reportes
                </a>
                @if (auth()->user()?->hasRole('admin_distribuidora'))
                    <a href="{{ route('distribuidora.configuracion') }}"
                        @click="sidebarOpen = false"
                        class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('distribuidora.configuracion') ? 'bg-white/15' : '' }}">
                        Configuración
                    </a>
                @endif
            </nav>
            <div class="p-4 border-t border-white/10 text-sm">
                <p class="text-white/70 truncate">{{ auth()->user()->nombre ?? '' }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="text-xs text-white/50 hover:text-white">
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            {{-- Barra superior móvil --}}
            <header class="lg:hidden sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3">
                <button
                    type="button"
                    class="rounded-md p-2 text-slate-700 hover:bg-slate-100"
                    @click="sidebarOpen = true"
                    aria-label="Abrir menú"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <div>
                    <p class="text-sm font-semibold text-slate-900">FootwearPoint</p>
                    <p class="text-xs text-slate-500">Panel distribuidora</p>
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
