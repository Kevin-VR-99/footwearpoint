@props([
    'ancho' => 'completo',
    'vacio' => null,
])

{{--
    A18 — El <select> estándar.

    Mismo caso que x-ui.entrada: la flechita, el borde y el foco ya vienen de
    la base de app.css; aquí solo se fijan radio y tamaño con los tokens. Se
    encontraron 6 formas distintas de escribir un <select> en las vistas.

    "vacio" es el texto de la primera opción, la que vale "" — el "Marca",
    "Categoría", "Temporada" que hoy se escribe a mano en cada formulario:

        <x-ui.selector wire:model="producto_marca_id" vacio="Marca">
            @foreach ($marcas as $marca)
                <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
            @endforeach
        </x-ui.selector>

    Las opciones van en el slot y no como arreglo: casi todas salen de un
    @foreach sobre modelos, y pasarlas como arreglo obligaría a aplanarlas
    antes en cada pantalla.
--}}

<select {{ $attributes->class(['fp-control', 'w-full' => $ancho === 'completo']) }}>
    @if ($vacio !== null)
        <option value="">{{ $vacio }}</option>
    @endif

    {{ $slot }}
</select>
