@props([
    'titulo',
    'confirmar',
    'cancelar' => null,
    'etiqueta' => 'Confirmar',
    'etiquetaCargando' => null,
    'variante' => 'peligro',
    'error' => null,
    'ancho' => 'max-w-md',
])

@php
    // Lista blanca, igual que x-panel.boton.
    $variante = in_array($variante, ['peligro', 'primario'], true) ? $variante : 'peligro';
    $etiquetaCargando ??= 'Un momento…';
@endphp

{{--
    TG-380 A20 — La forma de preguntar "¿seguro?" en toda la casa.

    Antes habia cuatro, y ninguna hacia lo mismo:

      - wire:confirm (25 usos, la entrega entre ellos): el dialogo del
        navegador. Sin estilo, sin estado de carga, y si la accion tarda
        no hay forma de avisar que esta en curso.
      - Un x-panel.modal armado a mano en cada pantalla (catalogo
        maestro, distribuidoras), cada uno con sus propios botones.
      - x-personas.modal-eliminar, que esta bien hecho pero solo sabe de
        personas.
      - Un <div role="dialog"> suelto dentro de la pagina, en cambios.

    Dos decisiones que no se ven leyendo el codigo por encima:

    - El foco arranca en CANCELAR, no en confirmar. Quien llega aqui
      acaba de pedir algo que no se deshace; si el Enter cae sobre el
      boton rojo, un teclazo de mas lo ejecuta.
    - El boton de confirmar se deshabilita mientras la accion corre y
      cambia de texto. Sin eso la gente vuelve a hacer clic creyendo que
      no paso nada, y la accion se dispara dos veces.

        <x-ui.confirmar titulo="¿Marcar listo para entrega?"
            confirmar="marcarListo" cancelar="cerrarAviso"
            etiqueta="Marcar listo" variante="primario">
            Se le avisara al cliente para que pase a recoger.
        </x-ui.confirmar>
--}}

<x-panel.modal :titulo="$titulo" :ancho="$ancho" :cerrar="$cancelar" data-modal="confirmar">
    <div class="text-sm text-slate-700">{{ $slot }}</div>

    @if ($error)
        <x-panel.alerta tipo="peligro" class="mt-4" data-error-confirmar>{{ $error }}</x-panel.alerta>
    @endif

    <x-slot:pie>
        @if ($cancelar)
            {{-- autofocus aqui a proposito: ver el comentario de arriba. --}}
            <button type="button" wire:click="{{ $cancelar }}" class="fp-btn fp-btn-secundario" autofocus>Cancelar</button>
        @endif

        <button type="button" wire:click="{{ $confirmar }}"
            class="fp-btn fp-btn-{{ $variante }}"
            wire:loading.attr="disabled" wire:target="{{ $confirmar }}" @disabled($error)>
            <span wire:loading.remove wire:target="{{ $confirmar }}">{{ $etiqueta }}</span>
            <span wire:loading wire:target="{{ $confirmar }}">{{ $etiquetaCargando }}</span>
        </button>
    </x-slot:pie>
</x-panel.modal>
