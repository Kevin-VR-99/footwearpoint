@props([
    'id' => 'banco',
    'modelo' => 'banco',
    'valor' => '',
    'etiqueta' => 'Banco',
    'clabeId' => null,
    'requerido' => false,
])

{{--
    Select de banco con buscador. La lista sale de App\Support\BancosMexico y
    la lógica vive en resources/js/select-banco.js (Alpine.data fpSelectBanco).
    Con clabeId, los tres primeros dígitos de ese campo eligen el banco.
    La lista va en el flujo (no flotante): las tarjetas de formulario recortan lo que se sale de ellas.
    El Alpine maneja su propio DOM, por eso wire:ignore: Livewire solo recibe
    el valor elegido.
--}}
<div
    class="fp-campo fp-campo-apilado"
    wire:ignore
    x-id="['banco']"
    x-data="fpSelectBanco({
        bancos: {{ \Illuminate\Support\Js::from(\App\Support\BancosMexico::paraSelect()) }},
        valor: {{ \Illuminate\Support\Js::from((string) $valor) }},
        modelo: {{ \Illuminate\Support\Js::from($modelo) }},
        clabeId: {{ \Illuminate\Support\Js::from($clabeId) }},
    })"
    @click.outside="cerrar()"
    data-select-banco
>
    <label for="{{ $id }}" class="fp-campo-etiqueta">
        {{ $etiqueta }}
        @if ($requerido)
            <span class="fp-campo-requerido" aria-label="obligatorio">*</span>
        @endif
    </label>

    <div class="relative">
        <input
            x-ref="campo"
            type="text"
            id="{{ $id }}"
            role="combobox"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            maxlength="80"
            placeholder="Busca o elige tu banco"
            class="fp-control w-full pr-10"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            :aria-expanded="abierto ? 'true' : 'false'"
            :aria-controls="$id('banco') + '-lista'"
            :aria-activedescendant="idActivo()"
            x-model="busqueda"
            @focus="abrir()"
            @click="abrir()"
            @input="alEscribir()"
            @keydown="alTeclear($event)"
            data-banco
        >
        <button
            type="button"
            tabindex="-1"
            class="absolute inset-y-0 right-1 my-1 rounded-md px-2 text-slate-500 hover:bg-slate-100"
            :aria-label="abierto ? 'Cerrar lista de bancos' : 'Mostrar lista de bancos'"
            @click="alternar()"
        >
            <svg class="h-4 w-4 transition-transform" :class="abierto ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7.5l5 5 5-5" /></svg>
        </button>

        <ul
            x-ref="lista"
            x-show="abierto"
            x-cloak
            x-transition.opacity.duration.100ms
            role="listbox"
            :id="$id('banco') + '-lista'"
            aria-label="Bancos de México"
            class="mt-1 max-h-60 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-sm"
            data-lista-bancos
        >
            <template x-for="(banco, i) in filtrados" :key="banco.nombre">
                <li
                    role="option"
                    :id="idOpcion(i)"
                    :aria-selected="banco.nombre === seleccionado ? 'true' : 'false'"
                    class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2"
                    :class="i === activo ? 'bg-fp-primary/10 text-slate-900' : 'text-slate-700 hover:bg-slate-50'"
                    @mousedown.prevent
                    @click="elegir(banco)"
                    @mousemove="activo = i"
                    data-opcion-banco
                >
                    <span x-text="banco.nombre" :class="banco.nombre === seleccionado ? 'font-semibold' : ''"></span>
                    <span class="text-xs tabular-nums text-slate-400" x-text="banco.clave" x-show="banco.clave !== ''"></span>
                </li>
            </template>
            <li x-show="filtrados.length === 0" class="px-3 py-2 text-slate-500" role="presentation">No encontré ese banco. Prueba con otra parte del nombre.</li>
        </ul>
    </div>

    <p class="sr-only" aria-live="polite" x-text="abierto ? resumenResultados : ''"></p>
    <p class="fp-campo-ayuda" x-show="aviso !== ''" x-cloak x-text="aviso" role="status" data-aviso-banco></p>
</div>