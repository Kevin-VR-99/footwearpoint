{{-- Modal de confirmación del trait ConfirmaAcciones: dice qué se va a borrar,
     Cancelar (o Esc, o clic afuera) cierra sin tocar nada y el botón rojo confirma.
        <x-ui.confirmar-accion :datos="$accionPorConfirmar" /> --}}
@props(['datos' => null])

@if ($datos)
    <x-ui.confirmar :titulo="$datos['titulo']" confirmar="confirmarAccion" cancelar="cancelarAccion"
        :etiqueta="$datos['etiqueta']" :etiqueta-cargando="$datos['cargando']" variante="peligro">
        <p data-detalle-confirmacion>{{ $datos['detalle'] }}</p>
    </x-ui.confirmar>
@endif
