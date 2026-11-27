/**
 * Campo de contraseña con botón de mostrar u ocultar, confirmación
 * opcional, barra de seguridad y aviso de filtración (sin bloquear).
 * Se registra como Alpine.data('fpCampoPassword') desde app.js.
 */
export default function fpCampoPassword(config = {}) {
    return {
        visible: false,
        visibleConfirmacion: false,
        conBarra: Boolean(config.conBarra),
        valor: '',
        fuerza: 'vacia',
        filtrada: false,
        avisoUrl: config.avisoUrl || '',
        _reloj: null,

        init() {
            const campo = this.$refs.campo;
            if (campo) {
                this.valor = campo.value || '';
                this.calcularFuerza();
                campo.addEventListener('input', () => {
                    this.valor = campo.value || '';
                    this.calcularFuerza();
                    this.programarAviso();
                });
                campo.addEventListener('blur', () => this.revisarFiltracion());
            }
        },

        alternar() {
            this.visible = !this.visible;
        },

        alternarConfirmacion() {
            this.visibleConfirmacion = !this.visibleConfirmacion;
        },

        etiquetaBoton() {
            return this.visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
        },

        etiquetaBotonConfirmacion() {
            return this.visibleConfirmacion ? 'Ocultar confirmación' : 'Mostrar confirmación';
        },

        calcularFuerza() {
            const v = this.valor || '';
            if (v.length === 0) {
                this.fuerza = 'vacia';
                this.filtrada = false;
                return;
            }

            let puntos = 0;
            if (v.length >= 8) puntos += 1;
            if (v.length >= 12) puntos += 1;
            if (/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/.test(v)) puntos += 1;
            if (/\d/.test(v)) puntos += 1;
            if (/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9]/.test(v) || /\s/.test(v)) puntos += 1;

            const minus = v.toLowerCase().replace(/\s+/g, '');
            const debil = /^(?:(.)\1+$|12345678|87654321|abcdefgh|qwerty\d*|password\d*)$/i.test(minus);
            if (debil || v.length < 8) {
                this.fuerza = 'debil';
                return;
            }
            if (puntos >= 4) {
                this.fuerza = 'fuerte';
                return;
            }
            this.fuerza = 'media';
        },

        textoFuerza() {
            return { vacia: '', debil: 'Débil', media: 'Media', fuerte: 'Fuerte' }[this.fuerza] || '';
        },

        programarAviso() {
            this.filtrada = false;
            if (this._reloj) {
                clearTimeout(this._reloj);
            }
            this._reloj = setTimeout(() => this.revisarFiltracion(), 800);
        },

        async revisarFiltracion() {
            if (this._reloj) {
                clearTimeout(this._reloj);
                this._reloj = null;
            }
            const v = this.valor || '';
            if (!this.avisoUrl || v.length < 8) {
                this.filtrada = false;
                return;
            }

            try {
                const token = this.csrf();
                const respuesta = await fetch(this.avisoUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(token ? { 'X-XSRF-TOKEN': token, 'X-CSRF-TOKEN': token } : {}),
                    },
                    body: JSON.stringify({ password: v }),
                });
                if (!respuesta.ok) {
                    this.filtrada = false;
                    return;
                }
                const datos = await respuesta.json();
                this.filtrada = Boolean(datos.aparecio);
            } catch (error) {
                this.filtrada = false;
            }
        },

        csrf() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) {
                return meta.content;
            }
            const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
            return match ? decodeURIComponent(match[1]) : '';
        },
    };
}
