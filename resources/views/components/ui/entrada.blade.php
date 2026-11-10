@props([
    'tipo' => 'text',
    'ancho' => 'completo',
])

@php
    // Lista blanca, igual que x-panel.boton: un tipo mal escrito no debe
    // convertirse en un <input> raro, y "file" tiene su propio estilo aparte.
    $tipo = in_array($tipo, ['text', 'search', 'number', 'email', 'tel', 'url', 'password', 'date', 'time', 'datetime-local'], true)
        ? $tipo
        : 'text';
@endphp

{{--
    A18 — El <input> estándar.

    El borde, el fondo y el foco ya se los da la base de app.css a cualquier
    control; esto solo fija el radio y el tamaño del texto con los tokens
    (.fp-control), que es justo lo que variaba: se encontraron 8 formas
    distintas de escribir un <input>, unas con rounded-lg y otras con
    rounded-md.

    "ancho" es completo por omisión porque así está escrito casi siempre;
    ancho="auto" lo deja medir lo suyo, para los de una tabla o un filtro.
    Cualquier utilidad que se le pase en class gana, como en los demás
    componentes de la casa.
--}}

<input
    type="{{ $tipo }}"
    {{ $attributes->class(['fp-control', 'w-full' => $ancho === 'completo']) }}
>
