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

    {{-- Rediseño del acceso (Gabriel, 7, 27 y 27 nov, tarde).
         - Celular y tableta (menos de 1024 px): el diseño de siempre, tarjeta
           blanca centrada con el logo dentro (x-auth.encabezado). Sin línea
           de marca, sin siluetas y sin la cabecera de escritorio.
         - Escritorio (lg): la composición de la app móvil (FondoCalzado,
           FpLineaMarca, FpLogo): fondo marino iluminado con siluetas de
           tenis, línea azul-rojo arriba, logo PNG centrado y el formulario
           en una tarjeta de vidrio claro. El panel lateral ya no se usa:
           se prioriza parecerse a la app.

         El fondo sale tambien a clientes (confirmar correo, regreso de Mercado
         Pago): su texto es para todos, sin hablar de revendedores ni mayoreo.

         Todo es CSS del bundle (app.css): ni <style> ni <script> en linea, que
         la CSP es script-src 'self'. --}}
    <div class="fp-acceso-marco">
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
            {{-- Mismo PNG que la app (assets/brand/logo-mark-white-192.png,
                 copiado en public/brand). El blanco del círculo va en el PNG.
                 El texto visible es el nombre; el alt va vacío para no oírlo dos veces. --}}
            <header class="fp-acceso-marca">
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
