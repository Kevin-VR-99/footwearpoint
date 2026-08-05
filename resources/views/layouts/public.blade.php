<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Marketplace — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#F5F6FA] text-slate-800 antialiased">
    <header class="bg-[#111E38] text-white">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-bold">FootwearPoint</h1>
                <p class="text-xs text-white/60">Directorio de distribuidoras</p>
            </div>
            <a href="{{ route('login') }}"
               class="text-sm px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 transition">
                Iniciar sesión
            </a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 mt-8">
        <div class="max-w-6xl mx-auto px-4 py-4 text-center text-xs text-slate-500">
            FootwearPoint — Marketplace
        </div>
    </footer>

    @livewireScripts
</body>
</html>