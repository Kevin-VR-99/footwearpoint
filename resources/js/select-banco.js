/**
 * Select de banco con buscador (lista de bancos de México).
 * Se registra como Alpine.data('fpSelectBanco') desde app.js. Sin JS en línea:
 * la CSP solo deja scripts de este mismo origen.
 *
 * Patrón combobox de ARIA: el campo de texto filtra, la lista es un listbox
 * y todo se maneja con teclado (flechas, Inicio, Fin, Enter, Esc y Tab).
 *
 * Si hay un campo de CLABE (config.clabeId), los tres primeros dígitos eligen
 * el banco solos mientras la persona no lo haya escogido a mano.
 */
export function normalizarTexto(texto) {
    return String(texto || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

export default function fpSelectBanco(config = {}) {
    const bancos = Array.isArray(config.bancos) ? config.bancos.slice() : [];
    const inicial = String(config.valor || '');

    // Un banco guardado antes de que hubiera lista se conserva como opción.
    if (inicial !== '' && !bancos.some((b) => b.nombre === inicial)) {
        bancos.unshift({ nombre: inicial, clave: '' });
    }

    return {
        bancos,
        modelo: config.modelo || 'banco',
        clabeId: config.clabeId || '',
        abierto: false,
        filtrando: false,
        busqueda: inicial,
        seleccionado: inicial,
        activo: -1,
        autodetectado: false,
        aviso: '',

        init() {
            const clabe = this.clabeId ? document.getElementById(this.clabeId) : null;
            if (clabe) {
                clabe.addEventListener('input', () => this.alCambiarClabe(clabe.value));
            }
        },

        // ---- Lista -------------------------------------------------------

        get filtrados() {
            const texto = this.filtrando ? normalizarTexto(this.busqueda) : '';
            if (texto === '') {
                return this.bancos;
            }
            return this.bancos.filter(
                (b) => normalizarTexto(b.nombre).includes(texto) || (b.clave !== '' && b.clave.startsWith(texto)),
            );
        },

        get resumenResultados() {
            const n = this.filtrados.length;
            if (n === 0) return 'Sin resultados';
            return n === 1 ? '1 banco' : `${n} bancos`;
        },

        idOpcion(i) {
            return `${this.$id('banco')}-op-${i}`;
        },

        idActivo() {
            return this.abierto && this.activo >= 0 && this.activo < this.filtrados.length
                ? this.idOpcion(this.activo)
                : null;
        },

        // ---- Abrir y cerrar ----------------------------------------------

        abrir() {
            if (this.abierto) return;
            this.abierto = true;
            this.filtrando = false;
            const i = this.filtrados.findIndex((b) => b.nombre === this.seleccionado);
            this.activo = i >= 0 ? i : 0;
            this.$nextTick(() => this.mostrarActivo());
        },

        cerrar() {
            this.abierto = false;
            this.filtrando = false;
            this.activo = -1;
            // Sin elegir nada nuevo, el campo vuelve al banco que ya estaba.
            this.busqueda = this.seleccionado;
        },

        alternar() {
            if (this.abierto) {
                this.cerrar();
            } else {
                this.$refs.campo.focus();
                this.abrir();
            }
        },

        // ---- Teclado y escritura -----------------------------------------

        alEscribir() {
            this.abierto = true;
            this.filtrando = true;
            this.activo = this.filtrados.length > 0 ? 0 : -1;
            this.$nextTick(() => this.mostrarActivo());
        },

        alTeclear(evento) {
            const total = this.filtrados.length;

            switch (evento.key) {
                case 'ArrowDown':
                    evento.preventDefault();
                    if (!this.abierto) {
                        this.abrir();
                        return;
                    }
                    if (total > 0) this.activo = (this.activo + 1) % total;
                    break;
                case 'ArrowUp':
                    evento.preventDefault();
                    if (!this.abierto) {
                        this.abrir();
                        return;
                    }
                    if (total > 0) this.activo = (this.activo - 1 + total) % total;
                    break;
                case 'Home':
                    if (this.abierto && total > 0) {
                        evento.preventDefault();
                        this.activo = 0;
                    }
                    break;
                case 'End':
                    if (this.abierto && total > 0) {
                        evento.preventDefault();
                        this.activo = total - 1;
                    }
                    break;
                case 'Enter':
                    // Con la lista abierta, Enter elige y no envía el formulario.
                    if (this.abierto) {
                        evento.preventDefault();
                        if (this.activo >= 0 && this.activo < total) {
                            this.elegir(this.filtrados[this.activo]);
                        }
                    }
                    break;
                case 'Escape':
                    if (this.abierto) {
                        evento.preventDefault();
                        this.cerrar();
                    }
                    break;
                case 'Tab':
                    if (this.abierto) this.cerrar();
                    break;
                default:
                    // Con un banco ya elegido, la primera tecla (letra o borrar) empieza una búsqueda nueva.
                    if (
                        !this.filtrando &&
                        this.seleccionado !== '' &&
                        this.busqueda === this.seleccionado &&
                        !evento.ctrlKey &&
                        !evento.metaKey &&
                        !evento.altKey &&
                        (evento.key.length === 1 || evento.key === 'Backspace' || evento.key === 'Delete')
                    ) {
                        this.busqueda = '';
                    }
                    return;
            }

            this.$nextTick(() => this.mostrarActivo());
        },

        mostrarActivo() {
            const id = this.idActivo();
            const lista = this.$refs.lista;
            const opcion = id && lista ? lista.querySelector(`[id="${id}"]`) : null;
            if (opcion && typeof opcion.scrollIntoView === 'function') {
                opcion.scrollIntoView({ block: 'nearest' });
            }
        },

        // ---- Elegir ------------------------------------------------------

        elegir(banco, autodetectado = false) {
            this.seleccionado = banco.nombre;
            this.busqueda = banco.nombre;
            this.autodetectado = autodetectado;
            this.abierto = false;
            this.filtrando = false;
            this.activo = -1;
            this.avisarSiNoCoincide(this.valorClabe());
            // Livewire lo manda con el siguiente guardar; no hace falta una petición aparte.
            this.$wire.$set(this.modelo, banco.nombre, false);
            if (!autodetectado) this.$refs.campo.focus();
        },

        limpiar() {
            this.elegir({ nombre: '', clave: '' });
            this.aviso = '';
        },

        // ---- CLABE -------------------------------------------------------

        valorClabe() {
            const clabe = this.clabeId ? document.getElementById(this.clabeId) : null;
            return clabe ? clabe.value : '';
        },

        bancoDeClabe(valor) {
            const digitos = String(valor || '').replace(/\D/g, '');
            if (digitos.length < 3) return null;
            return this.bancos.find((b) => b.clave === digitos.slice(0, 3)) || null;
        },

        alCambiarClabe(valor) {
            const detectado = this.bancoDeClabe(valor);

            if (detectado && (this.seleccionado === '' || this.autodetectado)) {
                if (detectado.nombre !== this.seleccionado) {
                    this.elegir(detectado, true);
                }
                this.aviso = '';
                return;
            }

            this.avisarSiNoCoincide(valor);
        },

        avisarSiNoCoincide(valor) {
            const detectado = this.bancoDeClabe(valor);
            this.aviso =
                detectado && this.seleccionado !== '' && detectado.nombre !== this.seleccionado
                    ? `Los primeros dígitos de la CLABE corresponden a ${detectado.nombre}. Revisa que el banco sea el correcto.`
                    : '';
        },
    };
}