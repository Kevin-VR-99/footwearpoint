{{--
    TG-282 — La pantalla que se ve cuando no hay internet.

    Quién la enseña: el service worker (public/sw.js), en dos casos.

    1. Cualquier pantalla que no sea el inicio. El panel son 61 vistas de
       Livewire que se dibujan en el servidor: sin red no hay nada que
       enseñar, y una copia vieja podría traer datos de otra distribuidora.
    2. El inicio, si todavía no se alcanzó a guardar su copia anónima.

    Por qué es una ruta de Laravel y no un .html suelto en public/: así el
    service worker la guarda igual que todo lo demás y se mantiene junto al
    resto de las vistas. No pide sesión a propósito — ver routes/web.php.
--}}
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sin conexión — FootwearPoint</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Sin @vite a propósito: esta página tiene que verse bien cuando no hay
         red, y el CSS compilado puede no estar en el caché. Lo poco que
         necesita va aquí. --}}
    <style>
        :root { --azul: #2563EB; --texto: #0F172A; --suave: #64748B; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #F8FAFC;
            color: var(--texto);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .caja { max-width: 28rem; text-align: center; }

        img { width: 96px; height: 96px; }

        h1 { margin: 20px 0 8px; font-size: 1.5rem; }

        p { margin: 0 0 20px; color: var(--suave); line-height: 1.55; }

        .acciones { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }

        button, .boton-suave {
            border: 0;
            border-radius: 10px;
            background: var(--azul);
            color: #fff;
            padding: 12px 22px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover, .boton-suave:hover { filter: brightness(1.08); }

        /* Acción secundaria: Reintentar sigue siendo el principal. */
        .boton-suave {
            display: inline-block;
            background: transparent;
            border: 1px solid var(--azul);
            color: var(--azul);
            text-decoration: none;
        }

        small { display: block; margin-top: 18px; color: var(--suave); }

        @media (prefers-color-scheme: dark) {
            body { background: #0B1220; color: #E2E8F0; }
            p, small { color: #94A3B8; }
            .boton-suave { border-color: #60A5FA; color: #60A5FA; }
        }
    </style>
</head>

<body>
    <main class="caja">
        <img src="{{ asset('brand/logo-mark-192.png') }}" alt="FootwearPoint" width="96" height="96">

        <h1>Sin conexión</h1>

        <p>
            FootwearPoint necesita internet para enseñarte datos al día: los precios,
            el stock y los pedidos cambian a cada rato y no queremos mostrarte algo viejo.
        </p>

        {{-- Un <a>, no un botón con JS: el inicio sí tiene copia guardada, así
             que esto funciona sin internet, y un enlace no lo puede bloquear
             el CSP como le pasó al onclick de Reintentar. --}}
        <div class="acciones">
            <button type="button" id="reintentar">Reintentar</button>
            <a class="boton-suave" href="/">Ir al inicio</a>
        </div>

        <small>Si acabas de recuperar la señal, toca Reintentar.</small>
    </main>

    {{-- El CSP bloquea onclick="": los nonces no cubren los manejadores
         puestos en atributo, solo los <script>. Con el onclick, este botón
         estuvo muerto en la única pantalla que se ve sin internet. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('reintentar').addEventListener('click', function () {
            location.reload();
        });
    </script>
</body>

</html>
