<?php

use App\Services\Busqueda\BusquedaGlobalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/*
 * Búsqueda global del panel (Ctrl+K / ⌘K). Pedidos por folio, clientes y
 * mayoristas por nombre/teléfono/correo, productos y variantes por SKU,
 * modelo o nombre. Aislada por distribuidora y por permisos (ver el servicio).
 */
new class extends Component {
    public string $q = '';

    public function getDisponibleProperty(): bool
    {
        return app(BusquedaGlobalService::class)->staff(Auth::user()) !== null;
    }

    public function getResultadosProperty(): array
    {
        return app(BusquedaGlobalService::class)->buscar(Auth::user(), $this->q);
    }
};
?>

<div class="contents">
@if ($this->disponible)
    @php
        $resultados = $this->resultados;
        $termino = trim($q);
        $indice = 0;
    @endphp
    <div x-data="{
            abierto: false,
            activo: 0,
            abrir() { this.abierto = true; this.activo = 0; this.$nextTick(() => this.$refs.campo.focus()); },
            cerrar() { this.abierto = false; },
            items() { return [...this.$refs.lista?.querySelectorAll('[data-resultado]') ?? []]; },
            mover(d) { const n = this.items().length; if (!n) return; this.activo = (this.activo + d + n) % n; this.items()[this.activo]?.scrollIntoView({ block: 'nearest' }); },
            ir() { const el = this.items()[this.activo]; if (el) { el.click(); } },
        }"
        @keydown.window.prevent.ctrl.k="abierto ? cerrar() : abrir()"
        @keydown.window.prevent.meta.k="abierto ? cerrar() : abrir()"
        @abrir-busqueda.window="abrir()"
        x-effect="$wire.q; activo = 0"
        data-busqueda-global>
        {{-- Campo del navbar: abre el modal. --}}
        <button type="button" @click="abrir()"
            class="group hidden items-center gap-2 rounded-xl border border-slate-200 bg-fp-page/70 px-3 py-1.5 text-sm text-fp-text-muted transition hover:border-fp-primary/40 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 motion-reduce:transition-none sm:inline-flex sm:w-56 lg:w-72"
            aria-haspopup="dialog" aria-label="Buscar en el panel (Ctrl+K)" data-abrir-busqueda>
            <svg class="h-4 w-4 shrink-0 text-slate-400 group-hover:text-fp-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg>
            <span class="flex-1 truncate text-left">Buscar…</span>
            <kbd class="rounded-md border border-slate-200 bg-white px-1.5 py-0.5 font-sans text-[10px] font-semibold text-slate-500"><span x-text="/Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl'">Ctrl</span> K</kbd>
        </button>
        <button type="button" @click="abrir()" class="fp-accion fp-accion-neutra sm:hidden" aria-label="Buscar en el panel">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg>
        </button>

        <template x-teleport="body">
            <div x-show="abierto" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center px-4 pt-[10vh]"
                role="dialog" aria-modal="true" aria-labelledby="busqueda-global-titulo" @keydown.escape.window="cerrar()">
                <div x-show="abierto" x-transition.opacity.duration.150ms class="fp-modal-fondo absolute inset-0 backdrop-blur-sm" @click="cerrar()" aria-hidden="true"></div>

                <div x-show="abierto"
                    x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
                    x-transition:enter-start="opacity-0 -translate-y-3 scale-[.98]"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    x-transition:leave="transition ease-in duration-100 motion-reduce:transition-none"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                    x-trap.noscroll="abierto"
                    class="relative w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/10"
                    data-modal-busqueda>
                    <h2 id="busqueda-global-titulo" class="sr-only">Búsqueda global</h2>
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4">
                        <svg class="h-5 w-5 shrink-0 text-fp-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/></svg>
                        <label for="busqueda-global-campo" class="sr-only">Buscar pedidos, clientes y productos</label>
                        <input id="busqueda-global-campo" x-ref="campo" type="text" enterkeyhint="go" autocomplete="off" spellcheck="false"
                            wire:model.live.debounce.250ms="q"
                            @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)" @keydown.enter.prevent="ir()"
                            placeholder="Folio, nombre, teléfono, correo, SKU o modelo"
                            role="combobox" aria-controls="busqueda-global-lista" :aria-expanded="abierto.toString()"
                            :aria-activedescendant="'resultado-' + activo"
                            class="h-14 w-full border-0 bg-transparent px-0 text-base text-slate-800 placeholder:text-slate-400 focus:ring-0" />
                        <span wire:loading wire:target="q" class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-fp-primary border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
                        <kbd class="hidden rounded-md border border-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 sm:block">Esc</kbd>
                    </div>

                    <div id="busqueda-global-lista" x-ref="lista" role="listbox" class="max-h-[60vh] overflow-y-auto p-2" aria-live="polite">
                        @if (mb_strlen($termino) < \App\Services\Busqueda\BusquedaGlobalService::MINIMO)
                            <p class="px-3 py-8 text-center text-sm text-fp-text-muted">Escribe al menos {{ \App\Services\Busqueda\BusquedaGlobalService::MINIMO }} caracteres.</p>
                        @elseif ($resultados === [])
                            <p class="px-3 py-8 text-center text-sm text-fp-text-muted" data-sin-resultados>Sin resultados para «{{ $termino }}».</p>
                        @else
                            @foreach ($resultados as $clave => $grupo)
                                <section class="fp-aparecer mb-1" data-grupo="{{ $clave }}" wire:key="grupo-{{ $clave }}">
                                    <h3 class="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-fp-text-muted">{{ $grupo['titulo'] }}</h3>
                                    @foreach ($grupo['items'] as $item)
                                        @php $i = $indice++; @endphp
                                        <a href="{{ $item['url'] }}" wire:navigate id="resultado-{{ $i }}" role="option" data-resultado
                                            :aria-selected="(activo === {{ $i }}).toString()"
                                            @mouseenter="activo = {{ $i }}" @click="cerrar()"
                                            :class="activo === {{ $i }} ? 'bg-fp-badge-info-bg text-fp-badge-info-fg' : 'text-slate-700'"
                                            class="flex items-center gap-3 rounded-xl px-3 py-2 transition-colors motion-reduce:transition-none">
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium">{{ $item['titulo'] }}</span>
                                                <span class="block truncate text-xs text-fp-text-muted">{{ $item['detalle'] }}</span>
                                            </span>
                                            <svg x-show="activo === {{ $i }}" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        </a>
                                    @endforeach
                                </section>
                            @endforeach
                        @endif
                    </div>
                    <div class="flex items-center gap-4 border-t border-slate-100 bg-fp-page/60 px-4 py-2 text-[11px] text-fp-text-muted">
                        <span><kbd class="font-semibold">↑</kbd> <kbd class="font-semibold">↓</kbd> navegar</span>
                        <span><kbd class="font-semibold">Enter</kbd> abrir</span>
                        <span><kbd class="font-semibold">Esc</kbd> cerrar</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
</div>
