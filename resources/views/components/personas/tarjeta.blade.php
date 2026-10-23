{{--
    Contenedor de las listas de personas: ancho completo, encabezado con título
    y botón principal, barra de filtros y la tabla. La tabla (.fp-tabla-personas)
    se vuelve tarjetas apiladas cuando el contenedor es angosto (container query).
--}}
@props(['titulo', 'acciones' => null, 'filtros' => null])

<section {{ $attributes->class(['relative fp-tarjeta p-4 sm:p-6 fp-aparecer']) }}>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-700">{{ $titulo }}</h2>
        @if ($acciones)
            <div class="flex flex-wrap items-center gap-2">{{ $acciones }}</div>
        @endif
    </div>
    @if ($filtros)
        <div {{ $filtros->attributes->class(['mb-4 flex flex-wrap items-center gap-2']) }}>{{ $filtros }}</div>
    @endif
    <div class="fp-personas-contenedor">
        {{ $slot }}
    </div>
</section>
