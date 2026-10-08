<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Tienda — FootwearPoint' }}</title>
    @isset($distribuidora)
        @if ($distribuidora->descripcion_publica)
            <meta name="description" content="{{ \Illuminate\Support\Str::limit($distribuidora->descripcion_publica, 160) }}">
        @endif
    @endisset
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

{{-- TG-233 (G14): tienda pública de una distribuidora. Mismo estilo que layouts/public. --}}
<body class="min-h-screen bg-[#F5F6FA] text-slate-800 antialiased">
    <header class="bg-[#111E38] text-white">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            @isset($distribuidora)
                <a href="{{ route('tienda', $distribuidora->slug) }}" class="flex items-center gap-3 min-w-0">
                    @if ($distribuidora->logotipo_url)
                        <img src="{{ $distribuidora->logotipo_url }}" alt="Logo {{ $distribuidora->nombre_comercial }}"
                             class="h-10 w-10 rounded-lg bg-white object-contain p-1">
                    @endif
                    <span class="min-w-0">
                        <span class="block text-lg font-bold truncate">{{ $distribuidora->nombre_comercial }}</span>
                        <span class="block text-xs text-white/60">Tienda en FootwearPoint</span>
                    </span>
                </a>
            @else
                <span class="text-lg font-bold">FootwearPoint</span>
            @endisset

            <a href="{{ route('marketplace') }}"
                class="shrink-0 text-sm px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 transition">
                Ver marketplace
            </a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 mt-8">
        <div class="max-w-6xl mx-auto px-4 py-4 text-center text-xs text-slate-500">
            @isset($distribuidora)
                {{ $distribuidora->nombre_comercial }} ·
            @endisset
            Tienda en FootwearPoint
        </div>
    </footer>

    @livewireScripts
</body>

</html>
