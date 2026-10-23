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

<body class="min-h-screen bg-fp-page text-slate-800 antialiased" x-data="{ sidebarOpen: false }"
    @keydown.escape.window="sidebarOpen = false">
    <div class="min-h-screen flex">
        {{-- TG-234: barra lateral plegable con el mismo patrón que layouts/panel (Alpine sidebarOpen). --}}
        <aside
            class="sticky top-0 z-40 flex h-screen shrink-0 flex-col overflow-hidden bg-gradient-to-b from-fp-sidebar via-fp-sidebar to-fp-accent text-white shadow-xl shadow-fp-sidebar/30 transition-[width] duration-200 ease-out"
            :class="sidebarOpen ? 'w-64' : 'w-0'" :aria-hidden="(!sidebarOpen).toString()" data-admin-sidebar>
            <div class="flex h-full w-64 flex-col">
            <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
            <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4">
                <div class="min-w-0">
                    <p class="flex items-center gap-2.5 text-lg font-bold tracking-tight"><img src="{{ asset('brand/logo-mark-white-40.png') }}" alt="" class="h-9 w-9 rounded-full bg-white/10 object-cover ring-2 ring-fp-primary/35" width="36" height="36">FootwearPoint</p>
                    <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-white/45">Administración general</p>
                </div>
                <button type="button"
                    class="rounded-lg p-1.5 text-white/60 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/50"
                    @click="sidebarOpen = false" aria-label="Cerrar menú">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-3">
                <x-panel.enlace-lateral :href="route('admin.dashboard')" :activo="request()->routeIs('admin.dashboard')"
                    icono="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6">Distribuidoras</x-panel.enlace-lateral>
                <x-panel.enlace-lateral :href="route('admin.planes')" :activo="request()->routeIs('admin.planes')"
                    icono="M3 7h18v10H3zM3 11h18">Planes</x-panel.enlace-lateral>
                <x-panel.enlace-lateral :href="route('admin.catalogo')" :activo="request()->routeIs('admin.catalogo')"
                    icono="M4 6h16M4 12h16M4 18h10">Catálogo maestro</x-panel.enlace-lateral>
                <x-panel.enlace-lateral :href="route('admin.catalogos')" :activo="request()->routeIs('admin.catalogos')"
                    icono="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16">Catálogos para importar</x-panel.enlace-lateral>
            </nav>

            <div class="border-t border-white/10 px-4 py-3">
                <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-white/35">Footwear Point</p>
                <p class="mt-0.5 text-[11px] text-white/50">Administración general</p>
            </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-200 ease-out">
            {{-- TG-234: mismo encabezado y menú de usuario que el panel de distribuidora. --}}
            <header
                class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/85">
                <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
                <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                    <button x-show="!sidebarOpen" x-cloak x-transition.opacity type="button"
                        class="group flex items-center gap-2.5 rounded-xl py-1 pr-2.5 pl-1 text-left transition hover:bg-fp-page focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40"
                        @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()"
                        aria-label="Abrir o cerrar menú">
                        <span
                            class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-fp-sidebar shadow-sm ring-2 ring-fp-primary/20 transition group-hover:ring-fp-primary/40">
                            <img src="{{ asset('brand/logo-mark-white-40.png') }}" alt=""
                                class="h-8 w-8 rounded-full object-cover" width="32" height="32">
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold tracking-tight text-fp-sidebar">Footwear
                                Point</span>
                            <span
                                class="hidden text-[10px] font-medium uppercase tracking-[0.14em] text-fp-text-muted sm:block">Admin</span>
                        </span>
                    </button>
                    <div class="flex-1"></div>
                    <x-panel.menu-usuario subtitulo="Administración general">
                        <a href="{{ route('marketplace') }}" target="_blank" rel="noopener"
                            class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-slate-700 transition hover:bg-fp-page"
                            role="menuitem" data-menu-marketplace>
                            <svg class="h-4 w-4 text-fp-text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M6 8h12l-1 12H7L6 8zM9 8V6a3 3 0 016 0v2" />
                            </svg>
                            Marketplace público
                        </a>
                        <a href="{{ route('admin.auditoria') }}"
                            class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium transition hover:bg-fp-page {{ request()->routeIs('admin.auditoria') ? 'bg-fp-page text-fp-primary' : 'text-slate-700' }}"
                            role="menuitem" data-menu-auditoria @if (request()->routeIs('admin.auditoria')) aria-current="page" @endif>
                            <svg class="h-4 w-4 text-fp-text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12l2 2 4-4M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z" />
                            </svg>
                            Auditoría
                        </a>
                        <a href="{{ route('admin.ingresos') }}"
                            class="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium transition hover:bg-fp-page {{ request()->routeIs('admin.ingresos') ? 'bg-fp-page text-fp-primary' : 'text-slate-700' }}"
                            role="menuitem" data-menu-ingresos @if (request()->routeIs('admin.ingresos')) aria-current="page" @endif>
                            <svg class="h-4 w-4 text-fp-text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z" />
                            </svg>
                            Mercado Pago
                        </a>
                    </x-panel.menu-usuario>
                </div>
            </header>
            <main class="fp-contenido flex-1 p-6 lg:p-8 overflow-auto">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui.toast-global />
    @livewireScripts
</body>

</html>