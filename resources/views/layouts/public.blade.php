<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Marketplace — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-[#F5F6FA] text-slate-800 antialiased">
    <header class="bg-[#111E38] text-white">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-bold">FootwearPoint</h1>
                {{-- TG-219: cada pantalla pública pone el suyo; el del marketplace es el de siempre. --}}
                <p class="text-xs text-white/60">{{ $subtitulo ?? 'Directorio de distribuidoras' }}</p>
            </div>
            @auth
                {{-- TG-219: la misma regla la usa la página pública de inicio. --}}
                @php
                    ['url' => $destino, 'texto' => $textoBoton] = \App\Support\PanelDelUsuario::para(auth()->user());
                @endphp
                <a href="{{ $destino }}"
                    class="text-sm px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 transition">
                    {{ $textoBoton }}
                </a>
            @else
                <a href="{{ route('login') }}"
                    class="text-sm px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 transition">
                    Iniciar sesión
                </a>
            @endauth
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 mt-8">
        <div class="max-w-6xl mx-auto px-4 py-4 text-center text-xs text-slate-500 space-y-2">
            <p>FootwearPoint — {{ $subtitulo ?? 'Marketplace' }}</p>
            {{-- TG-219 (E15-04): los legales se alcanzan desde cualquier pantalla pública. --}}
            <p class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('aviso-privacidad') }}" class="hover:underline">Aviso de privacidad</a>
                <a href="{{ route('terminos') }}" class="hover:underline">Términos y condiciones</a>
            </p>
        </div>
    </footer>

    @livewireScripts
</body>

</html>
