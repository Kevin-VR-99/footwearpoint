<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'FootwearPoint' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-fp-page text-slate-800">
    <div class="flex min-h-screen">
        <aside class="w-64 bg-fp-sidebar text-white flex-shrink-0">
            <div class="p-4 text-lg font-semibold border-b border-white/10">
                Footwear Point
            </div>
            <nav class="p-2 space-y-1">
                <a href="{{ route('distribuidora.inicio') }}" class="block px-3 py-2 rounded hover:bg-white/10">Inicio</a>
                <a href="{{ route('distribuidora.catalogo') }}" class="block px-3 py-2 rounded hover:bg-white/10">Catálogo</a>
                <a href="{{ route('distribuidora.pedidos') }}" class="block px-3 py-2 rounded hover:bg-white/10">Pedidos</a>
                <a href="{{ route('distribuidora.ciclos') }}" class="block px-3 py-2 rounded hover:bg-white/10">Ciclos de Compra</a>
                <a href="{{ route('distribuidora.stock') }}" class="block px-3 py-2 rounded hover:bg-white/10">Stock Local</a>
                <a href="{{ route('distribuidora.vales') }}" class="block px-3 py-2 rounded hover:bg-white/10">Vales</a>
                <a href="{{ route('distribuidora.reportes') }}" class="block px-3 py-2 rounded hover:bg-white/10">Reportes</a>
                <a href="{{ route('distribuidora.configuracion') }}" class="block px-3 py-2 rounded hover:bg-white/10">Configuración</a>
            </nav>
        </aside>
        <div class="flex-1 flex flex-col">
            <header class="bg-white border-b px-6 py-3 flex justify-end items-center">
                <span class="text-sm text-fp-text-muted">{{ auth()->user()->nombre ?? '' }}</span>
            </header>
            <main class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>