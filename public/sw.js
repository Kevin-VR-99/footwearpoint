/*
 * TG-282 — Service worker de FootwearPoint.
 *
 * Lo que hace y lo que NO hace, que aquí lo segundo importa más.
 *
 * El panel son 61 pantallas de Livewire: se dibujan en el servidor y cada
 * clic es una petición. No hay forma de que funcionen sin internet sin
 * reescribirlas, así que esto no lo intenta.
 *
 * Lo que sí hace: guardar los archivos que no cambian (CSS, JS, logos) para
 * que la app abra rápido, y enseñar una página propia cuando no hay conexión
 * en vez del error del navegador.
 *
 * Lo que NO guarda, a propósito: ninguna respuesta con datos. FootwearPoint
 * es multi-inquilino y cada distribuidora ve lo suyo; una página guardada
 * podría enseñarle a alguien datos de otra, o precios y stock viejos. Eso es
 * peor que no tener caché.
 */

const VERSION = 'fp-v1';
const ESTATICOS = `${VERSION}-estaticos`;
const PAGINA_SIN_CONEXION = '/sin-conexion';

// Lo mínimo para que la página de sin conexión no salga en blanco.
const BASICOS = [PAGINA_SIN_CONEXION, '/brand/logo-mark-192.png', '/favicon.png'];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches
            .open(ESTATICOS)
            // Uno por uno: si un archivo falla, los demás igual se guardan.
            // Con addAll, un solo 404 tira toda la instalación.
            .then((cache) => Promise.allSettled(BASICOS.map((ruta) => cache.add(ruta))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches
            .keys()
            .then((nombres) => Promise.all(
                nombres.filter((n) => !n.startsWith(VERSION)).map((n) => caches.delete(n)),
            ))
            .then(() => self.clients.claim()),
    );
});

/** Los archivos compilados llevan hash en el nombre, así que nunca cambian. */
function esEstatico(url) {
    return (
        url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/brand/')
        || url.pathname === '/favicon.ico'
        || url.pathname === '/favicon.png'
        || url.pathname === '/apple-touch-icon.png'
    );
}

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;

    // Solo GET: un POST que se repita desde el caché podría cobrar dos veces.
    if (peticion.method !== 'GET') {
        return;
    }

    const url = new URL(peticion.url);

    // Nada de otros dominios: ni el bucket de las fotos ni el API de la IA.
    if (url.origin !== self.location.origin) {
        return;
    }

    if (esEstatico(url)) {
        evento.respondWith(
            caches.match(peticion).then((guardado) => guardado || fetch(peticion).then((respuesta) => {
                if (respuesta.ok) {
                    const copia = respuesta.clone();
                    caches.open(ESTATICOS).then((cache) => cache.put(peticion, copia));
                }

                return respuesta;
            })),
        );

        return;
    }

    // Las pantallas siempre van al servidor. Si no hay internet, la página de
    // sin conexión; nunca una copia vieja con datos de alguien.
    if (peticion.mode === 'navigate') {
        evento.respondWith(
            fetch(peticion).catch(() => caches.match(PAGINA_SIN_CONEXION)),
        );
    }
});
