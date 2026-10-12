<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Marketplace — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @livewireStyles
</head>

{{-- TG-234: paleta fp-* del admin general y barra con la estructura del panel de distribuidora. --}}
<body class="flex min-h-screen flex-col bg-fp-page text-slate-800 antialiased">
    <header
        class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/85">
        <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2.5 sm:px-5">
            <a href="{{ route('marketplace') }}" class="group flex min-w-0 items-center gap-2.5 rounded-xl py-1 pr-2.5 pl-1 transition hover:bg-fp-page">
                <span
                    class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-fp-sidebar shadow-sm ring-2 ring-fp-primary/20 transition group-hover:ring-fp-primary/40">
                    <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="Logo FootwearPoint"
                        class="h-8 w-8 rounded-full object-cover" width="32" height="32">
                </span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-semibold tracking-tight text-fp-sidebar">FootwearPoint</span>
                    {{-- TG-219: cada pantalla pública pone el suyo; el del marketplace es el de siempre. --}}
                    <span class="block truncate text-[10px] font-medium uppercase tracking-[0.14em] text-fp-text-muted">{{ $subtitulo ?? 'Directorio de distribuidoras' }}</span>
                </span>
            </a>

            <div class="flex-1"></div>

            @auth
                {{-- TG-219: la misma regla la usa la página pública de inicio. --}}
                @php
                    ['url' => $destino, 'texto' => $textoBoton] = \App\Support\PanelDelUsuario::para(auth()->user());
                @endphp
                <a href="{{ $destino }}"
                    class="shrink-0 rounded-full bg-fp-sidebar px-4 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-fp-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40">
                    {{ $textoBoton }}
                </a>
            @else
                <a href="{{ route('login') }}"
                    class="shrink-0 rounded-full bg-fp-sidebar px-4 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-fp-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40">
                    Iniciar sesión
                </a>
            @endauth
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-5">
        {{ $slot }}
    </main>

    <footer class="mt-8 bg-gradient-to-b from-fp-sidebar to-fp-accent text-white/70">
        <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
        <div class="mx-auto flex max-w-6xl flex-col items-center gap-3 px-4 py-6 text-xs sm:flex-row sm:justify-between">
            <p class="flex items-center gap-2">
                <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="" class="h-6 w-6 rounded-full" width="24" height="24">
                <span>© {{ now()->year }} FootwearPoint — {{ $subtitulo ?? 'Marketplace' }}</span>
            </p>
            {{-- TG-219 (E15-04): los legales se alcanzan desde cualquier pantalla pública. --}}
            <p class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('aviso-privacidad') }}" class="hover:text-white hover:underline">Aviso de privacidad</a>
                <a href="{{ route('terminos') }}" class="hover:text-white hover:underline">Términos y condiciones</a>
            </p>
        </div>
    </footer>

    @livewireScripts
</body>

</html>
