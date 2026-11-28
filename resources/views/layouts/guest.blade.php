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

    {{-- Rediseño del acceso (Gabriel, 27 nov / aclaración 27 nov noche).
         - Celular (menos de 1024 px): la composición de la app Flutter
           (FondoCalzado, FpLineaMarca, FpLogo): fondo marino con siluetas
           de tenis, línea azul-rojo, logo PNG centrado, nombre y la tarjeta
           de vidrio claro.
         - Escritorio (lg): la composición de la ronda 2, aclarada: panel de
           marca a la izquierda (azul vivo, logo centrado, frase y etiquetas)
           y el formulario de vidrio claro a la derecha, sobre un fondo
           luminoso. Sin siluetas ni la escena centrada de la app.

         Lo decide app.css; esta vista trae las dos caras.

         El fondo sale tambien a clientes (confirmar correo, regreso de Mercado
         Pago): el texto del panel es para todos, sin hablar de revendedores
         ni mayoreo.

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
                {{-- Mismo PNG que la app (logo-mark-white-192.png). El círculo
                     blanco va en el PNG. El halo es una luz, no una placa. --}}
                <div class="fp-acceso-logo-panel">
                    <span class="fp-acceso-logo-halo" aria-hidden="true"></span>
                    <img src="{{ asset('brand/logo-mark-white-192.png') }}" alt=""
                        class="fp-acceso-panel-logo" width="192" height="192">
                </div>
                <p class="fp-acceso-panel-nombre">Footwear Point</p>

                <div class="fp-acceso-panel-texto">
                    <p class="fp-acceso-frase">Calzado, pedidos y entregas en un solo lugar.</p>
                    <p class="fp-acceso-subfrase">FootwearPoint conecta a la distribuidora con sus tiendas y sus clientes.</p>
                </div>

                <ul class="fp-acceso-puntos" aria-label="Lo que encuentras en FootwearPoint">
                    <li>Catálogo</li>
                    <li>Pedidos</li>
                    <li>Entregas</li>
                </ul>
            </div>
        </header>

        <div class="fp-acceso-linea" aria-hidden="true"></div>

        <div class="fp-acceso-siluetas" aria-hidden="true">
            <span class="fp-acceso-tenis fp-acceso-tenis-1"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-2"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-3"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-4"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-5"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-6"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-7"></span>
            <span class="fp-acceso-tenis fp-acceso-tenis-8"></span>
        </div>

        <div class="fp-acceso-lado">
            <header class="fp-acceso-marca">
                <span class="fp-acceso-marca-halo" aria-hidden="true"></span>
                <img src="{{ asset('brand/logo-mark-white-192.png') }}" alt=""
                    class="fp-acceso-marca-logo" width="192" height="192">
                <p class="fp-acceso-marca-nombre">Footwear Point</p>
            </header>

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
