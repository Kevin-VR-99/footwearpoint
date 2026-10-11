<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Admin — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-fp-page text-slate-800 antialiased">
    <div class="min-h-screen flex">
        {{-- Sidebar (TG-179: mismos colores que el panel de la distribuidora) --}}
        <aside class="w-64 shrink-0 bg-gradient-to-b from-fp-sidebar via-fp-sidebar to-fp-accent text-white flex flex-col shadow-xl shadow-fp-sidebar/30">
            <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
            <div class="px-5 py-4 border-b border-white/10">
                <h1 class="text-lg font-bold tracking-tight">FootwearPoint</h1>
                <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-white/45">Administración general</p>
            </div>

            <nav class="flex-1 p-3 space-y-1">
                <a href="{{ route('admin.dashboard') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.dashboard') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Distribuidoras
                </a>
                <a href="{{ route('admin.planes') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.planes') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Planes
                </a>
                <a href="{{ route('admin.categorias-directorio') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.categorias-directorio') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Categorías del directorio
                </a>
                <a href="{{ route('admin.catalogos') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.catalogos') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Catálogos para importar
                </a>
                <a href="{{ route('marketplace') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm text-white/70 transition hover:bg-white/10 hover:text-white" target="_blank">
                    Marketplace público
                </a>
            </nav>

            <div class="p-4 border-t border-white/10 text-sm">
                <p class="text-white/70 truncate">{{ auth()->user()->nombreVisible() ?? '' }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                    @csrf
                    <button class="text-xs text-white/50 hover:text-white">Cerrar sesión</button>
                </form>
            </div>
        </aside>

        {{-- Contenido --}}
        <main class="flex-1 p-6 lg:p-8 overflow-auto">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>

</html>
