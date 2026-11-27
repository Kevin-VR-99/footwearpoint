<!DOCTYPE html>
<html lang="es">
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <title>{{ $title ?? 'FootwearPoint' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- TG-282: para que se pueda instalar y abrir en su propia ventana. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#2563EB">
    <meta name="mobile-web-app-capable" content="yes">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="fp-acceso-fondo min-h-screen text-slate-800 antialiased">
    <div class="fp-acceso-capa" aria-hidden="true"></div>

    {{-- Rediseño del acceso (Gabriel, 7 y 27 nov).
         - Celular y tableta (menos de 1024 px): el diseño de siempre, tarjeta
           blanca centrada con el logo dentro (x-auth.encabezado). El panel
           no se muestra.
         - Escritorio (lg): panel de marca a un lado y el formulario en una
           tarjeta de vidrio oscuro sobre un fondo marino con manchas.
         Lo decide app.css; esta vista es la misma para los dos.

         El panel sale tambien a clientes (confirmar correo, regreso de Mercado
         Pago): su texto es para todos, sin hablar de revendedores ni mayoreo.

         Todo es CSS del bundle (app.css): ni <style> ni <script> en linea, que
         la CSP es script-src 'self'. --}}
    <div class="fp-acceso-marco">
        <header class="fp-acceso-panel">
            <div class="fp-acceso-panel-formas" aria-hidden="true">
                <span class="fp-acceso-burbuja fp-acceso-burbuja-1"></span>
                <span class="fp-acceso-burbuja fp-acceso-burbuja-2"></span>
                <span class="fp-acceso-burbuja fp-acceso-burbuja-3"></span>
                <span class="fp-acceso-burbuja fp-acceso-burbuja-4"></span>
                <span class="fp-acceso-burbuja fp-acceso-burbuja-5"></span>
                <span class="fp-acceso-anillo"></span>
            </div>

            <div class="fp-acceso-panel-contenido">
                {{-- Liquid glass solo aqui, como acento: la placa del logo. --}}
                <span class="fp-acceso-placa-logo fp-vidrio">
                    <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point"
                        class="fp-acceso-panel-logo" width="175" height="160">
                </span>

                <div class="fp-acceso-panel-texto">
                    <p class="fp-acceso-frase">Calzado, pedidos y entregas en un solo lugar.</p>
                    <p class="fp-acceso-subfrase">FootwearPoint conecta a la distribuidora con sus tiendas y sus clientes.</p>
                </div>

                <ul class="fp-acceso-puntos" aria-label="Lo que encuentras en FootwearPoint">
                    <li class="fp-vidrio-oscuro">Catálogo</li>
                    <li class="fp-vidrio-oscuro">Pedidos</li>
                    <li class="fp-vidrio-oscuro">Entregas</li>
                </ul>
            </div>
        </header>

        <div class="fp-acceso-lado">
            {{-- A21: <main> para que haya una region principal, igual que en los
                 demas layouts. Aqui NO va el enlace de salto: esta pantalla es
                 una sola tarjeta y no hay menu que saltarse. --}}
            <main id="contenido" tabindex="-1" class="fp-contenido w-full max-w-md">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
