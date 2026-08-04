<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — FootwearPoint</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="p-8 bg-slate-100">
    <div class="max-w-lg mx-auto bg-white rounded-xl shadow p-6">
        <h1 class="text-xl font-bold mb-2">Dashboard distribuidora</h1>
        <p class="text-slate-600 mb-4">Sesión iniciada como: {{ auth()->user()->nombre }}</p>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="rounded-lg bg-[#111E38] text-white px-4 py-2 hover:bg-[#1E2F52]">
                Cerrar sesión
            </button>
        </form>
    </div>
</body>
</html>