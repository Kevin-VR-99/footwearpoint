{{-- TG-390 (A30) — El encabezado de las pantallas de acceso, en un solo sitio.

     REDISEÑO 7 NOV. El logo se fue al panel de marca de layouts.guest, que
     sale en todas estas pantallas (a un lado en escritorio, arriba en
     celular). Si cada tarjeta siguiera trayendo el suyo, se veria dos veces.
     Aqui queda el trazo de marca (marino, azul y rojo) que abre la tarjeta.
     Es decoracion: va con aria-hidden.

     NO LLEVA TITULO A PROPOSITO. Cada pantalla tiene el suyo y dice cosas
     distintas ("Correo confirmado", "Ese enlace no sirve"). Si el titulo
     viviera aqui, las que ya tienen <h1> acabarian con dos, y el orden de
     encabezados es lo que usa un lector de pantalla para orientarse. --}}

<span {{ $attributes->class('fp-acceso-acento') }} aria-hidden="true" data-acento-acceso></span>
