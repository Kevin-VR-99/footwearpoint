@props([
    'id' => 'password',
    'modelo' => 'password',
    'modeloConfirmacion' => null,
    'etiqueta' => 'Contraseña',
    'etiquetaConfirmacion' => 'Confirmar contraseña',
    'error' => null,
    'errorConfirmacion' => null,
    'ayuda' => 'Mínimo 8, con letras y números.',
    'requerido' => false,
    'autocomplete' => 'new-password',
    'conBarra' => false,
    'avisoFiltracion' => null,
])

@php
    $error = $error ?? $modelo;
    $errorConfirmacion = $errorConfirmacion ?? ($modeloConfirmacion ? $modeloConfirmacion : null);
    $idConfirmacion = $id.'-confirmacion';
@endphp

<div
    {{ $attributes }}
    x-data="fpCampoPassword({ conBarra: {{ $conBarra ? 'true' : 'false' }} })"
>
    <x-ui.campo :etiqueta="$etiqueta" :para="$id" :error="$error" :ayuda="$modeloConfirmacion ? null : $ayuda" :requerido="$requerido">
        <div class="relative">
            <input
                x-ref="campo"
                :type="visible ? 'text' : 'password'"
                id="{{ $id }}"
                wire:model="{{ $modelo }}"
                autocomplete="{{ $autocomplete }}"
                @if ($requerido) required @endif
                minlength="8"
                class="fp-control w-full pr-24"
            >
            <button
                type="button"
                class="absolute inset-y-0 right-1 my-1 rounded-md px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
                @click="alternar()"
                :aria-pressed="visible ? 'true' : 'false'"
                :aria-label="etiquetaBoton()"
                x-text="visible ? 'Ocultar' : 'Mostrar'"
            ></button>
        </div>

        @if ($conBarra)
            <div x-show="fuerza !== 'vacia'" x-cloak class="mt-2" aria-live="polite">
                <div class="h-1.5 overflow-hidden rounded-full bg-slate-200">
                    <div
                        class="h-full transition-all"
                        :class="{
                            'w-1/3 bg-fp-danger': fuerza === 'debil',
                            'w-2/3 bg-amber-500': fuerza === 'media',
                            'w-full bg-emerald-600': fuerza === 'fuerte',
                        }"
                    ></div>
                </div>
                <p class="mt-1 text-xs text-slate-600" x-text="textoFuerza()"></p>
            </div>
        @endif

        @if ($avisoFiltracion)
            <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status">{{ $avisoFiltracion }}</p>
        @endif
    </x-ui.campo>

    @if ($modeloConfirmacion)
        <x-ui.campo :etiqueta="$etiquetaConfirmacion" :para="$idConfirmacion" :error="$errorConfirmacion" :ayuda="$ayuda" :requerido="$requerido">
            <div class="relative">
                <input
                    :type="visibleConfirmacion ? 'text' : 'password'"
                    id="{{ $idConfirmacion }}"
                    wire:model="{{ $modeloConfirmacion }}"
                    autocomplete="{{ $autocomplete }}"
                    @if ($requerido) required @endif
                    minlength="8"
                    class="fp-control w-full pr-24"
                >
                <button
                    type="button"
                    class="absolute inset-y-0 right-1 my-1 rounded-md px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
                    @click="alternarConfirmacion()"
                    :aria-pressed="visibleConfirmacion ? 'true' : 'false'"
                    :aria-label="etiquetaBotonConfirmacion()"
                    x-text="visibleConfirmacion ? 'Ocultar' : 'Mostrar'"
                ></button>
            </div>
        </x-ui.campo>
    @endif
</div>
