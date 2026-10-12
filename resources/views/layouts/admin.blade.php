<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'Admin — FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @livewireStyles
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
</head>

<body class="min-h-screen bg-fp-page text-slate-800 antialiased">
    <div class="min-h-screen flex">
        <aside class="w-64 shrink-0 bg-gradient-to-b from-fp-sidebar via-fp-sidebar to-fp-accent text-white flex flex-col shadow-xl shadow-fp-sidebar/30">
            <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
            <div class="px-5 py-4 border-b border-white/10">
                <h1 class="flex items-center gap-2.5 text-lg font-bold tracking-tight"><img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="" class="h-9 w-9 rounded-full bg-white/10 object-cover ring-2 ring-fp-primary/35" width="36" height="36">FootwearPoint</h1>
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
                <a href="{{ route('admin.catalogo') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.catalogo') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Catálogo maestro
                </a>
                <a href="{{ route('admin.categorias-directorio') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.categorias-directorio') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Categorías del directorio
                </a>
                <a href="{{ route('admin.catalogos') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.catalogos') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Catálogos para importar
                </a>
                <a href="{{ route('admin.auditoria') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm transition {{ request()->routeIs('admin.auditoria') ? 'bg-fp-primary/25 font-semibold text-white shadow-sm ring-1 ring-fp-primary/30' : 'text-white/80 hover:bg-white/10 hover:text-white' }}">
                    Auditoría
                </a>
                <a href="{{ route('marketplace') }}"
                    class="block rounded-xl px-3 py-2.5 text-sm text-white/70 transition hover:bg-white/10 hover:text-white" target="_blank">
                    Marketplace público
                </a>
            </nav>

            <div class="border-t border-white/10 px-4 py-3">
                <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-white/35">Footwear Point</p>
                <p class="mt-0.5 text-[11px] text-white/50">Administración general</p>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- TG-234: mismo encabezado y menú de usuario que el panel de distribuidora. --}}
            <header
                class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/85">
                <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
                <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                    <div class="flex-1"></div>
                    <x-panel.menu-usuario subtitulo="Administración general">
                        <a href="{{ route('marketplace') }}" target="_blank" rel="noopener"
                            class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-slate-700 transition hover:bg-fp-page"
                            role="menuitem">Marketplace público</a>
                    </x-panel.menu-usuario>
                </div>
            </header>
            <main class="flex-1 p-6 lg:p-8 overflow-auto">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>

</html>