{{--
    TG-224 (G3) — Página de error mínima, sin depender del layout autenticado
    (el error puede ocurrir sin sesión o al armar el propio panel).
    Nunca muestra el detalle técnico: ese solo va al log.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') — FootwearPoint</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    {{-- Si no hay build de Vite, la página se sigue mostrando (sin estilos de Tailwind). --}}
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased" style="font-family: system-ui, sans-serif;">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8 text-center">
                <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point" class="mx-auto h-28 w-auto object-contain">

                <p class="text-sm text-slate-500 mt-3">Error @yield('codigo')</p>
                <h1 class="text-2xl font-bold text-slate-900 mt-1">@yield('titulo')</h1>
                <p class="text-sm text-slate-500 mt-3">@yield('mensaje')</p>

                <div class="mt-6">
                    <a href="@yield('enlace', url('/'))" class="inline-block rounded-lg bg-[#111E38] text-white font-medium px-4 py-2.5 hover:bg-[#1E2F52] transition">
                        @yield('textoEnlace', 'Ir al inicio')
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
