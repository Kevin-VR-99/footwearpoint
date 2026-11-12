@props([
    'etiqueta' => null,
    'para' => null,
    'error' => null,
    'ayuda' => null,
    'requerido' => false,
])

{{--
    A18 — Un campo de formulario completo: etiqueta, control y el texto de
    abajo (ayuda o error).

    Por qué existe: en las vistas había 199 <label> y 128 bloques @error
    escritos a mano, y el mensaje de error se pintaba de 4 formas distintas.
    Cada formulario volvía a decidir lo mismo.

    El control va en el slot, no como prop: hay 179 <input> en el proyecto con
    todo tipo de atributos (wire:model, step, accept, x-on:...). Envolverlos
    obligaría a reinventar cada uno; así el control se escribe tal cual y lo
    único que se hereda es la estructura.

        <x-ui.campo etiqueta="Nombre" para="nombre" error="producto_nombre">
            <x-ui.entrada id="nombre" wire:model="producto_nombre" />
        </x-ui.campo>

    "error" es el nombre del campo tal como lo conoce la validación, no el
    mensaje: el componente lo busca con @error.
--}}

{{-- Las dos clases a proposito: .fp-campo es la de la casa (tiñe la
     etiqueta al enfocar) y .fp-campo-apilado es la maquetacion nueva. --}}
<div {{ $attributes->class(['fp-campo', 'fp-campo-apilado']) }}>
    @if ($etiqueta)
        <label @if ($para) for="{{ $para }}" @endif class="fp-campo-etiqueta">
            {{ $etiqueta }}
            @if ($requerido)
                {{-- El asterisco solo no se oye: el aria-label lo dice con palabras. --}}
                <span class="fp-campo-requerido" aria-label="obligatorio">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($error && isset($errors))
        @error($error)
            {{-- role="alert" para que el lector de pantalla lo diga al
                 aparecer; el campo ademas se marca en rojo desde app.css. --}}
            <p class="fp-campo-error" role="alert">{{ $message }}</p>
        @enderror
    @endif

    {{-- La ayuda se calla cuando hay error: dos textos chicos debajo del
         mismo control compiten entre sí y gana el ruido.

         $errors se consulta con isset porque Laravel solo lo comparte en
         una peticion web normal; sin esa guarda el componente revienta al
         dibujarse fuera de una (por ejemplo desde una prueba). --}}
    @php($hayError = $error && isset($errors) && $errors->has($error))

    @if ($ayuda && ! $hayError)
        <p class="fp-campo-ayuda">{{ $ayuda }}</p>
    @endif
</div>
