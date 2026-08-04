<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Admin — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
    <div class="min-h-screen flex">
        {{-- Sidebar --}}
        <aside class="w-64 bg-[#111E38] text-white flex flex-col">
            <div class="p-5 border-b border-white/10">
                <h1 class="text-lg font-bold">FootwearPoint</h1>
                <p class="text-xs text-white/60">Administración general</p>
            </div>

            <nav class="flex-1 p-3 space-y-1">
                <a href="{{ route('admin.dashboard') }}"
                   class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('admin.dashboard') ? 'bg-white/15' : '' }}">
                    Distribuidoras
                </a>
                <a href="{{ route('admin.planes') }}"
                   class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('admin.planes') ? 'bg-white/15' : '' }}">
                    Planes
                </a>
            </nav>

            <div class="p-4 border-t border-white/10 text-sm">
                <p class="text-white/70 truncate">{{ auth()->user()->nombre ?? '' }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                    @csrf
                    <button class="text-xs text-white/50 hover:text-white">Cerrar sesión</button>
                </form>
            </div>
        </aside>

        {{-- Contenido --}}
        <main class="flex-1 p-6 overflow-auto">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>