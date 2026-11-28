{{--
    Autocompletado de cliente o propietario. Al escribir salen sugerencias
    por nombre o teléfono; al elegir, queda un recuadro con opción de quitarlo.
    Sin select aparte. Sin JS ni CSS inline (CSP).
--}}
@props([
    'sugerencias',
    'elegido' => null,
    'termino' => '',
    'seleccionar' => 'seleccionarPropietario',
    'quitar' => 'quitarPropietario',
    'etiqueta' => 'Propietario',
    'placeholder' => 'Buscar por nombre o teléfono',
    'requerido' => true,
    'errorCampo' => 'propietario_id',
    'mensajeVacio' => 'No hay coincidencias con la búsqueda.',
    'etiquetaBuscador' => null,
])

@php
    $termino = trim((string) $termino);
    $fichaElegido = is_array($elegido) ? $elegido : ($elegido ? \App\Support\BusquedaDeCliente::ficha($elegido) : null);
    $lista = collect($sugerencias)->take(15)->map(function ($item) {
        return is_array($item) ? $item : \App\Support\BusquedaDeCliente::ficha($item);
    });
@endphp

<div {{ $attributes->only('class')->class('fp-autocompletado-cliente') }} data-autocompletado-cliente>
    <span class="fp-campo-etiqueta mb-1 block">
        {{ $etiqueta }}
        @if ($requerido)
            <span class="fp-campo-requerido" aria-label="obligatorio">*</span>
        @endif
    </span>

    @if ($fichaElegido)
        <div class="fp-autocompletado-elegido" data-cliente-elegido>
            <div>
                <p class="text-sm font-medium text-slate-800">{{ $fichaElegido['nombre'] }}</p>
                @if (($fichaElegido['telefono'] ?? '') !== '' || ($fichaElegido['detalle'] ?? '') !== '')
                    <p class="text-xs text-slate-500">
                        {{ $fichaElegido['telefono'] }}
                        @if (($fichaElegido['detalle'] ?? '') !== '')
                            <span>{{ $fichaElegido['telefono'] !== '' ? ' · ' : '' }}{{ $fichaElegido['detalle'] }}</span>
                        @endif
                    </p>
                @endif
            </div>
            <button type="button" wire:click="{{ $quitar }}" class="fp-btn fp-btn-fantasma fp-btn-sm shrink-0" data-quitar-cliente>
                Quitar
            </button>
        </div>
    @else
        <div class="relative">
            <x-ui.buscador {{ $attributes->except('class') }}
                placeholder="{{ $placeholder }}"
                etiqueta="{{ $etiquetaBuscador ?? $placeholder }}" />

            @if ($termino !== '')
                @if ($lista->isNotEmpty())
                    <ul class="fp-autocompletado-lista" role="listbox" data-sugerencias-cliente>
                        @foreach ($lista as $ficha)
                            <li role="none">
                                <button type="button" role="option"
                                    wire:click="{{ $seleccionar }}({{ (int) $ficha['id'] }})"
                                    class="fp-autocompletado-opcion"
                                    data-sugerencia-id="{{ (int) $ficha['id'] }}">
                                    <span class="block text-sm font-medium text-slate-800">{{ $ficha['nombre'] }}</span>
                                    @if (($ficha['telefono'] ?? '') !== '' || ($ficha['detalle'] ?? '') !== '')
                                        <span class="block text-xs text-slate-500">
                                            {{ $ficha['telefono'] }}
                                            @if (($ficha['detalle'] ?? '') !== '')
                                                {{ $ficha['telefono'] !== '' ? ' · ' : '' }}{{ $ficha['detalle'] }}
                                            @endif
                                        </span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p role="status" class="mt-1 text-xs text-slate-500" data-sin-coincidencias>{{ $mensajeVacio }}</p>
                @endif
            @endif
        </div>
    @endif

    @error($errorCampo)
        <p class="fp-campo-error mt-1" role="alert">{{ $message }}</p>
    @enderror
</div>
