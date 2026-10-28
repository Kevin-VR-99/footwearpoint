/*
 * TG-282 — Service worker de FootwearPoint.
 *
 * Lo que hace y lo que NO hace, que aquí lo segundo importa más.
 *
 * El panel son 61 pantallas de Livewire: se dibujan en el servidor y cada
 * clic es una petición. No hay forma de que funcionen sin internet sin
 * reescribirlas, así que esto no lo intenta.
 *
 * Lo que sí hace: guardar los archivos que no cambian, dejar el INICIO
 * disponible sin conexión, y enseñar una página propia en el resto.
 *
 * Lo que NO guarda, a propósito: ninguna respuesta con datos de alguien.
 * FootwearPoint es multi-inquilino y una página guardada podría enseñarle a
 * una distribuidora datos de otra. Por eso el inicio se guarda en su versión
 * ANÓNIMA (ver abajo) y ninguna otra pantalla se guarda.
 */

const VERSION = 'fp-v2';
const ESTATICOS = `${VERSION}-estaticos`;
const PAGINA_SIN_CONEXION = '/sin-conexion';
const INICIO = '/';

const BASICOS = [PAGINA_SIN_CONEXION, '/brand/logo-mark-192.png', '/favicon.png'];

/**
 * El inicio, pedido SIN cookies.
 *
 * Es la clave de que esto sea seguro. Con sesión, el inicio enseña un enlace
 * al panel de quien entró, y para armarlo resuelve a qué distribuidora
 * pertenece. Guardar esa versión significaría poder enseñársela a otro.
 *
 * Con credentials: 'omit' el servidor no ve sesión y devuelve el inicio
 * público, el mismo que ve cualquiera sin haber entrado. Esa copia sí se
 * puede guardar y enseñar a quien sea.
 *
 * Quien esté con sesión y se quede sin internet verá el inicio público, sin
 * su enlace al panel. Es lo correcto: más vale eso que el panel de otro.
 */
async function guardarInicioAnonimo(cache) {
    const respuesta = await fetch(INICIO, { credentials: 'omit', cache: 'reload' });

    if (respuesta.ok) {
        await cache.put(INICIO, respuesta);
    }
}

/**
 * Los compilados que el inicio necesita para verse bien.
 *
 * Sus nombres llevan hash y cambian en cada despliegue, así que se leen del
 * manifiesto de Vite en vez de escribirlos a mano. Sin esto, el inicio
 * offline se vería sin estilos hasta que alguien lo visitara una vez con
 * internet.
 */
async function guardarCompilados(cache) {
    const respuesta = await fetch('/build/manifest.json', { cache: 'reload' });

    if (!respuesta.ok) {
        return;
    }

    const manifiesto = await respuesta.json();
    const rutas = [];

    for (const entrada of Object.values(manifiesto)) {
        if (entrada.isEntry) {
            rutas.push(`/build/${entrada.file}`);

            for (const css of entrada.css ?? []) {
                rutas.push(`/build/${css}`);
            }
        }
    }

    await Promise.allSettled(rutas.map((ruta) => cache.add(ruta)));
}

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches
            .open(ESTATICOS)
            .then(async (cache) => {
                // Uno por uno: si un archivo falla, los demás igual se
                // guardan. Con addAll, un solo 404 tira toda la instalación.
                await Promise.allSettled(BASICOS.map((ruta) => cache.add(ruta)));
                await Promise.allSettled([guardarInicioAnonimo(cache), guardarCompilados(cache)]);
            })
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

function esInicio(url) {
    return url.pathname === INICIO;
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

    if (peticion.mode !== 'navigate') {
        return;
    }

    // El inicio: primero la red, y si no hay, la copia anónima guardada.
    // Primero la red siempre, para que quien tenga sesión vea SU inicio y
    // los planes estén al día; la copia es solo el paracaídas.
    if (esInicio(url)) {
        evento.respondWith(
            fetch(peticion)
                .then((respuesta) => {
                    // Se refresca la copia, pero solo la anónima: la respuesta
                    // que acaba de llegar puede traer el panel de quien entró.
                    if (respuesta.ok) {
                        caches.open(ESTATICOS).then((cache) => guardarInicioAnonimo(cache));
                    }

                    return respuesta;
                })
                .catch(() => caches.match(INICIO, { ignoreVary: true })
                    .then((guardado) => guardado || caches.match(PAGINA_SIN_CONEXION))),
        );

        return;
    }

    // El resto de las pantallas siempre van al servidor. Si no hay internet,
    // la página de sin conexión; nunca una copia vieja con datos de alguien.
    evento.respondWith(
        fetch(peticion).catch(() => caches.match(PAGINA_SIN_CONEXION)),
    );
});
