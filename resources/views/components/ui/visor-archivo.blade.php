{{-- TG-234: visor de archivos en modal (PDF en iframe, imagen con <img>, otros con botón de descarga).
     Se abre con: $dispatch('abrir-visor', { url, tipo, nombre }). Se cierra con X, Escape o clic afuera. --}}
<div x-data="{ abierto: false, url: '', tipo: '', nombre: '' }"
    x-on:abrir-visor.window="url = $event.detail.url; tipo = ($event.detail.tipo || '').toLowerCase(); nombre = $event.detail.nombre || 'Archivo'; abierto = true"
    x-on:keydown.escape.window="abierto = false" data-visor-archivo>
    <template x-teleport="body">
        <div x-show="abierto" x-cloak x-transition.opacity.duration.150ms
            class="fixed inset-0 z-50 flex items-center justify-center bg-fp-sidebar/70 p-4 backdrop-blur-sm"
            x-on:click.self="abierto = false" role="dialog" aria-modal="true" :aria-label="nombre">
            <div x-show="abierto" x-transition.scale.95.duration.150ms
                class="flex h-[88vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl shadow-fp-sidebar/40 ring-1 ring-fp-primary/20">
                <div class="h-0.5 w-full bg-gradient-to-r from-fp-primary via-fp-sidebar to-fp-danger"></div>
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <p class="min-w-0 truncate text-sm font-semibold text-fp-sidebar" x-text="nombre"></p>
                    <div class="flex items-center gap-2">
                        <a :href="url" download
                            class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-fp-primary transition hover:bg-fp-page">Descargar</a>
                        <button type="button" x-on:click="abierto = false" aria-label="Cerrar visor"
                            class="rounded-lg p-1.5 text-fp-text-muted transition hover:bg-fp-page hover:text-fp-sidebar focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 bg-fp-page">
                    <template x-if="abierto && tipo === 'pdf'">
                        <iframe :src="url" class="h-full w-full border-0" :title="nombre"></iframe>
                    </template>
                    <template x-if="abierto && ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(tipo)">
                        <div class="flex h-full items-center justify-center overflow-auto p-4">
                            <img :src="url" :alt="nombre" decoding="async" class="max-h-full max-w-full rounded-lg shadow-sm">
                        </div>
                    </template>
                    <template x-if="abierto && ! ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'].includes(tipo)">
                        <div class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                            <p class="text-sm text-fp-text-muted">Este tipo de archivo no se puede ver aquí.</p>
                            <a :href="url" download
                                class="fp-btn fp-btn-primario rounded-xl">Descargar archivo</a>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
