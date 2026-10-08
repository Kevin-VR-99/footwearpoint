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
        x-transition.opacity.duration.200ms
        class="pointer-events-auto max-w-sm rounded-lg px-4 py-3 text-sm font-medium shadow-lg ring-1 ring-black/5"
        :class="tipo === 'error'
            ? 'bg-fp-badge-danger-bg text-fp-badge-danger-fg'
            : 'bg-slate-900 text-white'"
    >
        <span x-text="mensaje"></span>
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
