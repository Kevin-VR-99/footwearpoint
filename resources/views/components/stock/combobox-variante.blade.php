{{-- Selector de variante con búsqueda: escribe SKU o modelo, o escanea el código
     (el lector USB teclea el SKU y manda Enter: se elige la coincidencia exacta).
     Busca en el servidor con buscarVariantes(); guarda el id en la propiedad "modelo". --}}
@props(['id', 'modelo', 'etiqueta' => 'Variante'])

<div x-data="{
        q: '', skuElegido: '', res: [], abierto: false, activo: 0, elegida: '', imagenElegida: '', fotoAbierta: false, buscando: false, ultimo: 0,
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
            this.elegida = r.etiqueta; this.imagenElegida = r.imagen || ''; this.q = r.sku; this.skuElegido = r.sku; this.ultimo++; this.buscando = false; this.abierto = false;
        },
        async enter() {
            await this.buscar();
            const t = this.q.trim().toLowerCase();
            const exacta = this.res.find(r => r.sku.toLowerCase() === t);
            if (exacta) return this.elegir(exacta);
            if (this.res.length === 1) return this.elegir(this.res[0]);
            if (this.abierto && this.res[this.activo]) return this.elegir(this.res[this.activo]);
        },
        limpiar() { this.q = ''; this.skuElegido = ''; this.res = []; this.elegida = ''; this.imagenElegida = ''; this.fotoAbierta = false; this.abierto = false; $wire.$set('{{ $modelo }}', '', false); },
    }"
    x-on:stock-limpiar-variantes.window="q = ''; skuElegido = ''; res = []; elegida = ''; imagenElegida = ''; fotoAbierta = false; abierto = false"
    @keydown.escape.window="fotoAbierta = false"
    @click.outside="abierto = false" class="relative" data-combobox-variante="{{ $modelo }}">
    <label for="{{ $id }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $etiqueta }}</label>
    <div class="flex items-start gap-2">
        <button type="button" x-show="imagenElegida" x-cloak @click="fotoAbierta = true" data-ver-imagen-stock
            class="group inline-flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:border-fp-primary/50 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 motion-reduce:transform-none"
            aria-label="Ver la foto del calzado">
            <template x-if="imagenElegida"><img :src="imagenElegida" alt="" class="h-full w-full object-contain transition duration-300 group-hover:scale-110 motion-reduce:transform-none" width="40" height="40"></template>
        </button>
    <div class="relative min-w-0 flex-1">
        <input id="{{ $id }}" type="text" x-model="q" autocomplete="off" role="combobox"
            :aria-expanded="abierto" aria-controls="{{ $id }}-lista" aria-autocomplete="list"
            @input.debounce.200ms="if (q === skuElegido) return; elegida = ''; imagenElegida = ''; skuElegido = ''; $wire.$set('{{ $modelo }}', '', false); buscar()"
            @keydown.enter.prevent="enter()"
            @keydown.arrow-down.prevent="if (res.length) { abierto = true; activo = (activo + 1) % res.length }"
            @keydown.arrow-up.prevent="if (res.length) { activo = (activo - 1 + res.length) % res.length }"
            @keydown.escape="abierto = false"
            class="w-full rounded-lg text-sm focus:ring-fp-primary pr-8"
            placeholder="SKU, modelo o escanea el código">
        <span x-show="buscando" x-cloak class="absolute right-2.5 top-2.5 h-4 w-4 animate-spin rounded-full border-2 border-fp-primary border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
        <button type="button" x-show="elegida && !buscando" x-cloak @click="limpiar()" class="absolute right-2 top-2 text-slate-400 hover:text-slate-600" aria-label="Quitar variante">×</button>
    </div>
    </div>
    <p x-show="elegida" x-cloak class="mt-1 text-xs text-fp-success" x-text="'Seleccionada: ' + elegida" data-variante-elegida></p>
    <ul id="{{ $id }}-lista" x-show="abierto" x-cloak role="listbox"
        x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
        <template x-for="(r, i) in res" :key="r.id">
            <li role="option" :aria-selected="i === activo" @mousedown.prevent="elegir(r)" @mouseenter="activo = i"
                :class="i === activo ? 'bg-fp-primary/10 text-fp-sidebar' : 'text-slate-700'" class="flex cursor-pointer items-center gap-2 px-3 py-1.5">
                <template x-if="r.imagen"><img :src="r.imagen" alt="" class="h-8 w-8 shrink-0 rounded border border-slate-200 bg-white object-contain" width="32" height="32"></template>
                <span class="min-w-0">
                    <span class="font-mono text-xs" x-text="r.sku"></span>
                    <span class="block text-xs text-slate-500" x-text="r.detalle"></span>
                </span>
            </li>
        </template>
        <li x-show="res.length === 0" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</li>
    </ul>
    <template x-teleport="body">
        <div x-show="fotoAbierta" x-cloak
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="fp-modal-fondo fixed inset-0 z-50 flex items-center justify-center p-4"
            @click.self="fotoAbierta = false" role="dialog" aria-modal="true" aria-label="Foto del calzado" data-modal-imagen-stock>
            <div class="relative w-full max-w-[360px] overflow-hidden rounded-2xl bg-white p-3 shadow-2xl shadow-fp-sidebar/40 ring-1 ring-fp-primary/20">
                <button type="button" @click="fotoAbierta = false"
                    class="absolute right-2 top-2 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-fp-sidebar shadow ring-1 ring-slate-200"
                    aria-label="Cerrar foto">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <template x-if="imagenElegida"><img :src="imagenElegida" alt="" class="aspect-square w-full rounded-xl bg-fp-page object-contain"></template>
                <p class="mt-2 truncate px-1 text-center text-sm font-medium text-fp-sidebar" x-text="elegida"></p>
            </div>
        </div>
    </template>
</div>
