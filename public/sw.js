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
 *
 * Dos reglas que no se ven leyendo el código por encima:
 *
 * - Nunca se espera a la red más de ESPERA_MAXIMA. Esa espera es pantalla
 *   en blanco, y teniendo una copia lista no tiene sentido aguantarla.
 * - La copia del inicio se entrega con la franja de aviso ya descubierta
 *   (copiaDelInicio). Quien la ve tiene que saber que puede estar vieja:
 *   el inicio enseña precios de planes.
 *
 * Orden de este archivo: constantes, ayudantes sueltos, las funciones que
 * guardan, install y activate, y al final fetch, que es quien decide qué se
 * responde a cada petición.
 */

const VERSION = 'fp-v3';
const ESTATICOS = `${VERSION}-estaticos`;
const PAGINA_SIN_CONEXION = '/sin-conexion';
const INICIO = '/';

const BASICOS = [PAGINA_SIN_CONEXION, '/brand/logo-mark-192.png', '/favicon.png'];

/**
 * Cuánto se espera a la red antes de echar mano de la copia.
 *
 * Con el cable desconectado la petición falla enseguida, pero si el aparato
 * sigue conectado a un wifi que no llega a ningún lado, el navegador espera
 * hasta medio minuto a que la conexión se rinda. Todo ese rato la pantalla
 * está en blanco, y es justo cuando teníamos una copia lista que enseñar.
 *
 * Seis segundos: de sobra para un servidor que despierta frio, poco para que
 * alguien crea que la página se rompió.
 */
const ESPERA_MAXIMA = 6000;

/** El trozo del layout que mantiene la franja escondida. */
const MARCA_DEL_AVISO = 'id="fp-aviso-sin-red" hidden';

/** Lo que el navegador sabe de la red. Miente a veces, pero cuando dice que no hay, no hay. */
function sinRed() {
    return self.navigator.onLine === false;
}

function conLimite(promesa) {
    return new Promise((resolver, rechazar) => {
        const reloj = setTimeout(() => rechazar(new Error('la red tardó demasiado')), ESPERA_MAXIMA);

        promesa.then(
            (valor) => { clearTimeout(reloj); resolver(valor); },
            (error) => { clearTimeout(reloj); rechazar(error); },
        );
    });
}

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

    await Promise.allSettled(rutas.map(async (ruta) => {
        if (!(await cache.match(ruta))) {
            await cache.add(ruta);
        }
    }));
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
            // Aquí NO se pide nada por red. Mientras activate no termina, el
            // navegador retiene todas las peticiones: una descarga colgada
            // deja la pantalla en blanco hasta que se rinde. El reintento del
            // guardado vive en la respuesta del inicio, que no bloquea nada.
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

/**
 * La copia del inicio, con la franja de aviso ya visible.
 *
 * La página decide enseñar la franja mirando navigator.onLine, y eso miente
 * cuando el aparato sigue conectado a un wifi sin salida: justo ahí hace
 * falta el aviso y no saldría. Como aquí sabemos de cierto que lo que se
 * entrega es una copia, se le quita el "hidden" de una vez.
 */
async function copiaDelInicio() {
    const guardado = await caches.match(INICIO, { ignoreVary: true });

    if (!guardado) {
        return caches.match(PAGINA_SIN_CONEXION);
    }

    const html = await guardado.text();
    const cabeceras = new Headers(guardado.headers);

    // El largo cambia al quitarle el atributo.
    cabeceras.delete('content-length');

    return new Response(html.replace(MARCA_DEL_AVISO, 'id="fp-aviso-sin-red"'), {
        status: guardado.status,
        statusText: guardado.statusText,
        headers: cabeceras,
    });
}

/**
 * El inicio: primero la red, y si tarda o no hay, la copia anónima.
 *
 * Primero la red siempre, para que quien tenga sesión vea SU inicio y los
 * planes estén al día; la copia es solo el paracaídas.
 */
async function responderInicio(evento) {
    // Si el navegador ya sabe que no hay red, esperar a que la petición
    // falle solo regala segundos de pantalla en blanco.
    if (sinRed()) {
        return copiaDelInicio();
    }

    try {
        const respuesta = await conLimite(fetch(evento.request));

        // Se refresca la copia, pero solo la anónima: la respuesta que acaba
        // de llegar puede traer el panel de quien entró.
        //
        // Con waitUntil el navegador no apaga el worker hasta que la
        // escritura termine. Sin él puede matarlo en cuanto responde, y la
        // copia se queda a medio guardar. Ese es también el reintento: si al
        // instalar falló, la primera visita con internet lo arregla.
        if (respuesta.ok) {
            evento.waitUntil(
                caches.open(ESTATICOS).then((cache) => guardarInicioAnonimo(cache)),
            );
        }

        return respuesta;
    } catch (error) {
        return copiaDelInicio();
    }
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

    if (esInicio(url)) {
        evento.respondWith(responderInicio(evento));

        return;
    }

    // El resto de las pantallas siempre van al servidor. Si no hay internet,
    // la página de sin conexión; nunca una copia vieja con datos de alguien.
    //
    // Aquí no se le pone límite a la espera: no hay copia equivalente que
    // enseñar, y cortarle a un reporte lento sería cambiar la página buena
    // por un aviso de que no hay red.
    if (sinRed()) {
        evento.respondWith(caches.match(PAGINA_SIN_CONEXION));

        return;
    }

    evento.respondWith(
        fetch(peticion).catch(() => caches.match(PAGINA_SIN_CONEXION)),
    );
});
