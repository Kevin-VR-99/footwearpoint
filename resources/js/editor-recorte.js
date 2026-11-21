/**
 * TG-377 (A17) — Mover y estirar el recuadro del recorte sobre la página.
 *
 * El recuadro vive SIEMPRE en fracciones de 0 a 1 de la página, igual que lo
 * devuelve la IA, y nunca en pixeles. Es lo que hace que funcione igual en un
 * monitor grande que en una laptop, y que lo que se guarde no dependa de a
 * qué tamaño se estaba viendo la imagen.
 *
 * Se usa con pointer events y no con mouse: así el mismo código sirve con
 * dedo en una tableta, que es donde alguien revisaría un catálogo sentado
 * lejos de su escritorio.
 */

/** Por debajo de esto el recuadro ya no es una foto de nada. */
const MINIMO = 0.02;

const entre = (valor, minimo, maximo) => Math.min(maximo, Math.max(minimo, valor));

window.editorDeRecorte = (inicial) => ({
    x: inicial.x,
    y: inicial.y,
    ancho: inicial.ancho,
    alto: inicial.alto,

    /** Qué se está arrastrando: null, 'mover', o la esquina que se estira. */
    gesto: null,
    desde: null,

    get estilo() {
        return `left:${this.x * 100}%;top:${this.y * 100}%;width:${this.ancho * 100}%;height:${this.alto * 100}%`;
    },

    /** La posición del puntero en fracciones de la página. */
    punto(evento) {
        const caja = this.$refs.lienzo.getBoundingClientRect();

        return {
            x: (evento.clientX - caja.left) / caja.width,
            y: (evento.clientY - caja.top) / caja.height,
        };
    },

    empezar(evento, gesto) {
        // Capturar el puntero: si el dedo o el mouse se sale de la imagen a
        // media maniobra, los eventos siguen llegando aquí en vez de perderse.
        evento.target.setPointerCapture?.(evento.pointerId);

        this.gesto = gesto;
        this.desde = { ...this.punto(evento), x0: this.x, y0: this.y, ancho0: this.ancho, alto0: this.alto };
    },

    mover(evento) {
        if (!this.gesto) {
            return;
        }

        const ahora = this.punto(evento);
        const dx = ahora.x - this.desde.x;
        const dy = ahora.y - this.desde.y;

        if (this.gesto === 'mover') {
            this.x = entre(this.desde.x0 + dx, 0, 1 - this.ancho);
            this.y = entre(this.desde.y0 + dy, 0, 1 - this.alto);

            return;
        }

        this.estirar(this.gesto, dx, dy);
    },

    /**
     * Estirar desde una esquina deja quieta la de enfrente.
     *
     * Por eso al tirar de la izquierda cambian la x Y el ancho a la vez: el
     * borde derecho no se debe mover.
     */
    estirar(esquina, dx, dy) {
        const d = this.desde;

        if (esquina.includes('e')) {
            this.ancho = entre(d.ancho0 + dx, MINIMO, 1 - d.x0);
        }

        if (esquina.includes('s')) {
            this.alto = entre(d.alto0 + dy, MINIMO, 1 - d.y0);
        }

        if (esquina.includes('w')) {
            const derecha = d.x0 + d.ancho0;
            this.x = entre(d.x0 + dx, 0, derecha - MINIMO);
            this.ancho = derecha - this.x;
        }

        if (esquina.includes('n')) {
            const abajo = d.y0 + d.alto0;
            this.y = entre(d.y0 + dy, 0, abajo - MINIMO);
            this.alto = abajo - this.y;
        }
    },

    soltar() {
        this.gesto = null;
        this.desde = null;
    },

    /** Más o menos aire, sin tocar el centro. Para afinar sin pulso fino. */
    aire(cuanto) {
        const centroX = this.x + this.ancho / 2;
        const centroY = this.y + this.alto / 2;
        const ancho = Math.max(MINIMO, this.ancho * (1 + cuanto));
        const alto = Math.max(MINIMO, this.alto * (1 + cuanto));

        this.x = entre(centroX - ancho / 2, 0, 1 - MINIMO);
        this.y = entre(centroY - alto / 2, 0, 1 - MINIMO);
        this.ancho = Math.min(ancho, 1 - this.x);
        this.alto = Math.min(alto, 1 - this.y);
    },

    /**
     * Manda el recuadro y pide el recorte.
     *
     * El recuadro se manda diferido (el tercer argumento en false) para que
     * no haya una petición por cada pixel que se arrastra: viaja junto con la
     * llamada a guardar.
     */
    guardar() {
        this.$wire.set('recorteCaja', { x: this.x, y: this.y, ancho: this.ancho, alto: this.alto }, false);
        this.$wire.guardarRecorte();
    },
});
