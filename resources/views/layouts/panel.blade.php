<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Panel — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-[#F5F6FA] text-slate-800 antialiased">
    <div class="min-h-screen flex">
        {{-- Sidebar --}}
        <aside class="w-64 bg-[#111E38] text-white flex flex-col shrink-0">
            <div class="p-5 border-b border-white/10">
                <h1 class="text-lg font-bold">FootwearPoint</h1>
                <p class="text-xs text-white/60">Panel distribuidora</p>
            </div>

            <nav class="flex-1 p-3 space-y-1">
                <a href="{{ route('dashboard') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'bg-white/15' : '' }}">
                    Inicio
                </a>
                <a href="{{ route('stock.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('stock.*') ? 'bg-white/15' : '' }}">
                    Stock
                </a>                <a href="{{ route('punto-venta.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('punto-venta.*') ? 'bg-white/15' : '' }}">
                    Punto de Venta
                </a>                <a href="{{ route('ciclo.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('ciclo.*') ? 'bg-white/15' : '' }}">
                    Ciclo de compra
                </a>                <a href="{{ route('vales.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('vales.*') ? 'bg-white/15' : '' }}">
                    Vales
                </a>
                <a href="{{ route('notificaciones.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('notificaciones.*') ? 'bg-white/15' : '' }}">
                    Notificaciones
                </a>
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

        <main class="flex-1 p-6 overflow-auto">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>

</html>
