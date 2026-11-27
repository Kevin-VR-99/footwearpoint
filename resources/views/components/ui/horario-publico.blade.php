@props([
    'wireGrupos' => 'horarios_publicos',
    'wireTexto' => 'horario_publico',
    'grupos' => [],
    'textoLibre' => '',
    'error' => 'horario_publico',
])

@php
    $grupos = is_array($grupos) ? $grupos : [];
    $textoLibre = (string) $textoLibre;
    $tieneEstructura = collect($grupos)->contains(fn ($g) => ! empty($g['dias'] ?? []));
    $modo = (! $tieneEstructura && $textoLibre !== '') ? 'texto' : 'editor';
@endphp

<div
    class="md:col-span-2"
    wire:ignore.self
    x-data="fpHorarioPublico({
        grupos: {{ \Illuminate\Support\Js::from($grupos) }},
        textoLibre: {{ \Illuminate\Support\Js::from($textoLibre) }},
        modo: {{ \Illuminate\Support\Js::from($modo) }},
        propGrupos: {{ \Illuminate\Support\Js::from($wireGrupos) }},
        propTexto: {{ \Illuminate\Support\Js::from($wireTexto) }},
    })"
>
    <div class="fp-campo fp-campo-apilado">
        <p class="fp-campo-etiqueta">Horario público</p>

        <div x-show="modo === 'texto'" x-cloak class="space-y-2">
            <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700" x-text="textoLibre"></p>
            <p class="text-xs text-slate-500">Este horario está guardado como texto. Puedes dejarlo así o armarlo con el formulario para tener días y horas por separado.</p>
            <button type="button" class="fp-btn fp-btn-secundario fp-btn-sm" @click="rearmar()">Armar con el formulario</button>
        </div>

        <div x-show="modo === 'editor'" x-cloak class="space-y-3">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-slate-500">Plantilla:</span>
                <button type="button" class="fp-btn fp-btn-secundario fp-btn-sm" @click="aplicarPlantilla('lv_9_18')">L–V 9:00–18:00</button>
                <button type="button" class="fp-btn fp-btn-secundario fp-btn-sm" @click="aplicarPlantilla('ls_9_19')">L–S 9:00–19:00</button>
                <button type="button" class="fp-btn fp-btn-secundario fp-btn-sm" @click="aplicarPlantilla('24_horas')">24 horas</button>
            </div>

            <template x-for="(grupo, indice) in grupos" :key="indice">
                <div class="rounded-xl border border-slate-200 bg-white p-3 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-medium text-slate-600">Horario <span x-text="indice + 1"></span></p>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-slate-700">
                                <input type="checkbox" class="rounded" :checked="grupo.cerrado" @change="alternarCerrado(indice)">
                                Cerrado
                            </label>
                            <button type="button" class="fp-enlace text-xs" @click="quitarGrupo(indice)" x-show="grupos.length > 1">Quitar</button>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5" role="group" :aria-label="'Días del horario ' + (indice + 1)">
                        <template x-for="dia in dias" :key="dia">
                            <button
                                type="button"
                                class="min-w-9 rounded-lg border px-2 py-1 text-sm font-semibold"
                                :class="tieneDia(grupo, dia)
                                    ? 'border-fp-primary bg-fp-primary text-white'
                                    : (diaEnOtroGrupo(indice, dia)
                                        ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400'
                                        : 'border-slate-300 bg-white text-slate-700 hover:border-fp-primary')"
                                :aria-pressed="tieneDia(grupo, dia) ? 'true' : 'false'"
                                :aria-label="etiquetas[dia]"
                                :disabled="diaEnOtroGrupo(indice, dia)"
                                @click="alternarDia(indice, dia)"
                                x-text="chips[dia]"
                            ></button>
                        </template>
                    </div>

                    <div x-show="!grupo.cerrado" class="space-y-2">
                        <template x-for="(rango, r) in (grupo.rangos || [])" :key="r">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="sr-only" :for="'horario-' + indice + '-inicio-' + r">Hora de inicio</label>
                                <input
                                    type="time"
                                    class="fp-control w-auto"
                                    :id="'horario-' + indice + '-inicio-' + r"
                                    x-model="rango[0]"
                                    @change="alCambiar()"
                                >
                                <span class="text-sm text-slate-500">a</span>
                                <label class="sr-only" :for="'horario-' + indice + '-fin-' + r">Hora de fin</label>
                                <input
                                    type="time"
                                    class="fp-control w-auto"
                                    :id="'horario-' + indice + '-fin-' + r"
                                    x-model="rango[1]"
                                    @change="alCambiar()"
                                >
                                <button type="button" class="fp-enlace text-xs" @click="quitarRango(indice, r)" x-show="grupo.rangos.length > 1">Quitar rango</button>
                            </div>
                        </template>
                        <button type="button" class="fp-enlace text-sm" @click="agregarRango(indice)">+ Agregar rango</button>
                    </div>
                </div>
            </template>

            <button type="button" class="fp-btn fp-btn-secundario fp-btn-sm" @click="agregarGrupo()">+ Agregar horario</button>
        </div>

        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2" x-show="vistaPrevia()" x-cloak>
            <p class="text-xs font-medium text-slate-500">Vista previa</p>
            <p class="text-sm text-slate-800" x-text="vistaPrevia()"></p>
        </div>

        <ul x-show="errores.length" x-cloak class="mt-2 space-y-1" role="alert">
            <template x-for="errorCliente in errores" :key="errorCliente">
                <li class="fp-campo-error text-xs" x-text="errorCliente"></li>
            </template>
        </ul>

        @if ($error && isset($errors))
            @error($error)
                <p class="fp-campo-error mt-1" role="alert">{{ $message }}</p>
            @enderror
        @endif

        <p class="fp-campo-ayuda mt-1">Elige los días abiertos y sus horas. Puedes agregar más de un rango el mismo día, por ejemplo 9:00–14:00 y 16:00–20:00.</p>
    </div>
</div>
