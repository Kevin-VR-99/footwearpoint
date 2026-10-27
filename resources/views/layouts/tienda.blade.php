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
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    {{-- TG-282: para que se pueda instalar y abrir en su propia ventana. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#2563EB">
    <meta name="mobile-web-app-capable" content="yes">
    @livewireStyles
</head>

{{-- TG-233 (G14): tienda pública de una distribuidora. Mismo estilo que layouts/public. --}}
<body class="flex min-h-screen flex-col bg-fp-page text-slate-800 antialiased">
    <header
        class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/85">
        <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-2.5 sm:px-5">
            @isset($distribuidora)
                <a href="{{ app(\App\Services\Tienda\EnlaceTienda::class)->url($distribuidora) }}" class="flex min-w-0 items-center gap-2.5 rounded-xl py-1 pr-2.5 pl-1 transition hover:bg-fp-page">
                    @if ($distribuidora->logotipo_url)
                        <img src="{{ $distribuidora->logotipo_url }}" alt="Logo {{ $distribuidora->nombre_comercial }}"
                             width="40" height="40" decoding="async"
                             class="h-10 w-10 shrink-0 rounded-full bg-white object-contain p-0.5 shadow-sm ring-2 ring-fp-primary/20">
                    @else
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-fp-sidebar ring-2 ring-fp-primary/20">
                            <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="Logo FootwearPoint" class="h-8 w-8 rounded-full object-cover" width="32" height="32">
                        </span>
                    @endif
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-semibold tracking-tight text-fp-sidebar sm:text-base">{{ $distribuidora->nombre_comercial }}</span>
                        <span class="block text-[10px] font-medium uppercase tracking-[0.14em] text-fp-text-muted">Tienda en FootwearPoint</span>
                    </span>
                </a>
            @else
                <span class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-fp-sidebar ring-2 ring-fp-primary/20">
                        <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="Logo FootwearPoint" class="h-8 w-8 rounded-full object-cover" width="32" height="32">
                    </span>
                    <span class="text-sm font-semibold text-fp-sidebar">FootwearPoint</span>
                </span>
            @endisset

            <a href="{{ app(\App\Services\Tienda\EnlaceTienda::class)->marketplace() }}"
                class="shrink-0 rounded-full bg-fp-sidebar px-4 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-fp-accent">
                Ver marketplace
            </a>
        </div>
    </header>

    <main class="fp-contenido mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-5">
        {{ $slot }}
    </main>

    <footer class="mt-8 bg-gradient-to-b from-fp-sidebar to-fp-accent text-white/70">
        <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
        <div class="mx-auto flex max-w-6xl items-center justify-center gap-2 px-4 py-6 text-center text-xs">
            <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="" class="h-6 w-6 rounded-full" width="24" height="24">
            <span>
                © {{ now()->year }}
                @isset($distribuidora)
                    {{ $distribuidora->nombre_comercial }} ·
                @endisset
                Tienda en FootwearPoint
            </span>
        </div>
    </footer>

    @livewireScripts
</body>

</html>
