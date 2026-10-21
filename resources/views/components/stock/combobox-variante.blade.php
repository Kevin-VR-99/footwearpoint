{{-- Selector de variante con búsqueda: escribe SKU o modelo, o escanea el código
     (el lector USB teclea el SKU y manda Enter: se elige la coincidencia exacta).
     Busca en el servidor con buscarVariantes(); guarda el id en la propiedad "modelo". --}}
@props(['id', 'modelo', 'etiqueta' => 'Variante'])

<div x-data="{
        q: '', skuElegido: '', res: [], abierto: false, activo: 0, elegida: '', buscando: false, ultimo: 0,
        async buscar() {
            const n = ++this.ultimo;
            if (this.q.trim() === '') { this.res = []; this.abierto = false; return; }
            this.buscando = true;
            const r = await $wire.buscarVariantes(this.q);
            if (n !== this.ultimo) return;
            this.res = r; this.activo = 0; this.abierto = true; this.buscando = false;
        },
        elegir(r) {
            $wire.$set('{{ $modelo }}', String(r.id), false);
            this.elegida = r.etiqueta; this.q = r.sku; this.skuElegido = r.sku; this.ultimo++; this.buscando = false; this.abierto = false;
        },
        async enter() {
            await this.buscar();
            const t = this.q.trim().toLowerCase();
            const exacta = this.res.find(r => r.sku.toLowerCase() === t);
            if (exacta) return this.elegir(exacta);
            if (this.res.length === 1) return this.elegir(this.res[0]);
            if (this.abierto && this.res[this.activo]) return this.elegir(this.res[this.activo]);
        },
        limpiar() { this.q = ''; this.skuElegido = ''; this.res = []; this.elegida = ''; this.abierto = false; $wire.$set('{{ $modelo }}', '', false); },
    }"
    x-on:stock-limpiar-variantes.window="q = ''; skuElegido = ''; res = []; elegida = ''; abierto = false"
    @click.outside="abierto = false" class="relative" data-combobox-variante="{{ $modelo }}">
    <label for="{{ $id }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $etiqueta }}</label>
    <div class="relative">
        <input id="{{ $id }}" type="text" x-model="q" autocomplete="off" role="combobox"
            :aria-expanded="abierto" aria-controls="{{ $id }}-lista" aria-autocomplete="list"
            @input.debounce.200ms="if (q === skuElegido) return; elegida = ''; skuElegido = ''; $wire.$set('{{ $modelo }}', '', false); buscar()"
            @keydown.enter.prevent="enter()"
            @keydown.arrow-down.prevent="if (res.length) { abierto = true; activo = (activo + 1) % res.length }"
            @keydown.arrow-up.prevent="if (res.length) { activo = (activo - 1 + res.length) % res.length }"
            @keydown.escape="abierto = false"
            class="w-full rounded-lg text-sm focus:ring-fp-primary pr-8"
            placeholder="SKU, modelo o escanea el código">
        <span x-show="buscando" x-cloak class="absolute right-2.5 top-2.5 h-4 w-4 animate-spin rounded-full border-2 border-fp-primary border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
        <button type="button" x-show="elegida && !buscando" x-cloak @click="limpiar()" class="absolute right-2 top-2 text-slate-400 hover:text-slate-600" aria-label="Quitar variante">×</button>
    </div>
    <p x-show="elegida" x-cloak class="mt-1 text-xs text-fp-success" x-text="'Seleccionada: ' + elegida" data-variante-elegida></p>
    <ul id="{{ $id }}-lista" x-show="abierto" x-cloak role="listbox"
        x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
        <template x-for="(r, i) in res" :key="r.id">
            <li role="option" :aria-selected="i === activo" @mousedown.prevent="elegir(r)" @mouseenter="activo = i"
                :class="i === activo ? 'bg-fp-primary/10 text-fp-sidebar' : 'text-slate-700'" class="cursor-pointer px-3 py-1.5">
                <span class="font-mono text-xs" x-text="r.sku"></span>
                <span class="block text-xs text-slate-500" x-text="r.detalle"></span>
            </li>
        </template>
        <li x-show="res.length === 0" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</li>
    </ul>
</div>
