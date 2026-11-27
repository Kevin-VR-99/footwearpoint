/**
 * Campo de contraseña con botón de mostrar u ocultar, confirmación
 * opcional y (si se pide) barra de seguridad. Se registra como
 * Alpine.data('fpCampoPassword') desde app.js.
 */
export default function fpCampoPassword(config = {}) {
    return {
        visible: false,
        visibleConfirmacion: false,
        conBarra: Boolean(config.conBarra),
        valor: '',
        fuerza: 'vacia',

        init() {
            const campo = this.$refs.campo;
            if (campo) {
                this.valor = campo.value || '';
                this.calcularFuerza();
                campo.addEventListener('input', () => {
                    this.valor = campo.value || '';
                    this.calcularFuerza();
                });
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
    };
}
