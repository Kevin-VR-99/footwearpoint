<div
    x-data="{
        visible: false,
        mensaje: '',
        tipo: 'ok',
        timer: null,
        mostrar(mensaje, tipo = 'ok') {
            this.mensaje = mensaje || 'Listo';
            this.tipo = tipo;
            this.visible = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.visible = false }, 3500);
        }
    }"
    x-on:fp-toast.window="mostrar($event.detail.mensaje, $event.detail.tipo || 'ok')"
    class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex justify-center px-4 sm:inset-x-auto sm:right-4 sm:justify-end"
    aria-live="polite"
>
    <div
        x-show="visible"
        x-cloak
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="fp-toast pointer-events-auto relative flex max-w-sm items-start gap-3 overflow-hidden rounded-xl px-4 py-3 text-sm font-medium shadow-lg ring-1 ring-black/5"
        :class="tipo === 'error'
            ? 'bg-fp-badge-danger-bg text-fp-badge-danger-fg'
            : 'bg-fp-sidebar text-white'"
        :role="tipo === 'error' ? 'alert' : 'status'"
        data-toast
    >
        <svg x-show="tipo !== 'error'" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
        <svg x-show="tipo === 'error'" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" /></svg>
        <span class="flex-1" x-text="mensaje"></span>
        <button type="button" @click="visible = false" class="-mr-1 rounded p-0.5 opacity-70 transition hover:opacity-100" aria-label="Cerrar aviso">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
        <template x-if="visible"><span class="fp-toast-tiempo absolute inset-x-0 bottom-0 h-0.5 bg-current opacity-30" aria-hidden="true"></span></template>
    </div>
</div>

<script>
    document.addEventListener('livewire:init', () => {
        const emitir = (mensaje, tipo = 'ok') => {
            window.dispatchEvent(new CustomEvent('fp-toast', {
                detail: { mensaje, tipo },
            }));
        };

        const leerMensaje = (payload) => {
            if (payload == null) return null;
            if (typeof payload === 'string') return payload;
            if (Array.isArray(payload)) {
                const first = payload[0] ?? null;
                if (typeof first === 'string') return first;
                if (first && typeof first === 'object') {
                    return first.mensaje ?? first.message ?? null;
                }
            }
            if (typeof payload === 'object') {
                return payload.mensaje ?? payload.message ?? null;
            }
            return null;
        };

        Livewire.on('guardado', (payload) => {
            emitir(leerMensaje(payload) ?? 'Guardado correctamente.', 'ok');
        });

        Livewire.on('error-negocio', (payload) => {
            emitir(leerMensaje(payload) ?? 'No se pudo completar la operación.', 'error');
        });
    });
</script>
