{{-- TG-390 (A30) — El encabezado de las pantallas de acceso, en un solo sitio.

     POR QUE EXISTE. Las siete pantallas que comparten layouts.guest traian
     cada una su propia etiqueta <img>, y ya se habian separado: el login lo
     ensenaba a h-28 y las otras seis a h-16. Nadie lo decidio, se fue yendo.
     Con un solo componente no pueden volver a separarse.

     DOS CARAS (Gabriel, 27 nov). En celular el logo va aqui, en la tarjeta,
     como siempre. En escritorio (lg) el logo ya sale en el panel de marca de
     layouts.guest, asi que aqui se oculta y queda el trazo de color, que es
     decoracion (aria-hidden). Lo decide app.css, no la vista.

     "logo" en false: la pantalla nunca llevo logo (contrasena temporal) y
     solo se pone el trazo de escritorio.

     NO LLEVA TITULO A PROPOSITO. Cada pantalla tiene el suyo y dice cosas
     distintas ("Correo confirmado", "Ese enlace no sirve"). Si el titulo
     viviera aqui, las que ya tienen <h1> acabarian con dos, y el orden de
     encabezados es lo que usa un lector de pantalla para orientarse. --}}
@props(['logo' => true])

<div {{ $attributes->class('fp-acceso-encabezado') }}>
    @if ($logo)
        <div class="fp-acceso-logo text-center">
            <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point"
                class="mx-auto h-16 w-auto object-contain" width="160" height="64">
        </div>
    @endif
    <span class="fp-acceso-acento" aria-hidden="true" data-acento-acceso></span>
</div>
