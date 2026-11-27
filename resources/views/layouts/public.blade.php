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
    {{-- TG-282: para que se pueda instalar y abrir en su propia ventana. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#2563EB">
    <meta name="mobile-web-app-capable" content="yes">
    @livewireStyles
</head>

{{-- TG-234: paleta fp-* del admin general y barra con la estructura del panel de distribuidora. --}}
<body class="flex min-h-screen flex-col bg-fp-page text-slate-800 antialiased">
    <x-ui.saltar-al-contenido />
    {{-- TG-282: el inicio se puede ver sin internet, desde una copia
         guardada. Decirlo evita que alguien tome por vigente un precio o un
         plan que quizá ya cambió. El script va suelto y no depende del JS
         compilado, que offline podría no estar. Lleva nonce porque el CSP
         (script-src 'self' 'nonce-...') bloquea cualquier script sin él. --}}
    <div id="fp-aviso-sin-red" hidden
        class="bg-fp-badge-warning-bg px-4 py-2 text-center text-sm text-fp-badge-warning-fg">
        Sin conexión: estás viendo una copia guardada, puede no estar al día.
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var aviso = document.getElementById('fp-aviso-sin-red');
            function revisar() { aviso.hidden = navigator.onLine; }
            revisar();
            window.addEventListener('online', revisar);
            window.addEventListener('offline', revisar);
        })();
    </script>
    <header
        class="sticky top-0 z-30 border-b border-slate-200/80 fp-vidrio shadow-sm">
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

            {{-- TG-391 (A31): atajos del encabezado público. «Home» va primero,
                 a la izquierda de Marketplace, y lleva al inicio (/). En el
                 celular no caben junto al botón: ahí van en el menú de abajo. --}}
            @php
                $enlacesPublicos = [
                    ['Home', route('inicio'), request()->routeIs('inicio')],
                    ['Marketplace', route('marketplace'), request()->routeIs('marketplace')],
                    ['Planes', route('inicio').'#planes', false],
                ];
            @endphp
            <nav aria-label="Páginas públicas" class="hidden items-center gap-1 sm:flex" data-nav-publica>
                @foreach ($enlacesPublicos as [$textoNav, $urlNav, $actualNav])
                    <a href="{{ $urlNav }}" @if ($actualNav) aria-current="page" @endif
                        class="rounded-full px-3 py-1.5 text-sm font-medium transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 {{ $actualNav ? 'bg-fp-page text-fp-sidebar' : 'text-slate-600 hover:bg-fp-page hover:text-fp-sidebar' }}">
                        {{ $textoNav }}
                    </a>
                @endforeach
            </nav>

            {{-- Menú del celular: <details> abre y cierra sin JavaScript (CSP). --}}
            <details class="relative sm:hidden" data-nav-publica-movil>
                <summary class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-full text-slate-600 transition hover:bg-fp-page hover:text-fp-sidebar focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 [&::-webkit-details-marker]:hidden"
                    aria-label="Abrir menú">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </summary>
                <nav aria-label="Páginas públicas" class="absolute right-0 z-40 mt-2 w-48 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                    @foreach ($enlacesPublicos as [$textoNav, $urlNav, $actualNav])
                        <a href="{{ $urlNav }}" @if ($actualNav) aria-current="page" @endif
                            class="block rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 {{ $actualNav ? 'bg-fp-page text-fp-sidebar' : 'text-slate-700 hover:bg-fp-page hover:text-fp-sidebar' }}">
                            {{ $textoNav }}
                        </a>
                    @endforeach
                </nav>
            </details>

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

    <main id="contenido" tabindex="-1" class="fp-contenido mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-5">
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
                {{-- TG-401 (A38): se puede cambiar de opinión cuando sea. Empieza
                     escondido y lo enseña el JavaScript; sin JavaScript no abriría
                     nada y sería un control muerto. --}}
                <button type="button" data-abrir-consentimiento hidden
                    class="hover:text-white hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70 rounded">
                    Preferencias de cookies
                </button>
            </p>
        </div>
    </footer>

    <x-ui.consentimiento-cookies />

    @livewireScripts
</body>

</html>
