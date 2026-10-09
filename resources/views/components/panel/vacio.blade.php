@props([
    'mensaje' => null,
    'colspan' => null,
])

{{-- TG-179: estado vacío. Con colspan se pinta como fila de tabla. --}}
@if ($colspan)
    <tr>
        <td colspan="{{ $colspan }}" {{ $attributes->class(['px-4 py-10 text-center text-sm text-fp-text-muted']) }}>
            {{ $mensaje }}{{ $slot }}
        </td>
    </tr>
@else
    <div {{ $attributes->class(['px-4 py-10 text-center text-sm text-fp-text-muted']) }}>
        {{ $mensaje }}{{ $slot }}
    </div>
@endif
