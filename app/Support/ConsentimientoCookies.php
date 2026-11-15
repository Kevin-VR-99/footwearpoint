<?php

namespace App\Support;

/**
 * TG-401 (A38) — La regla del consentimiento, en un solo lugar.
 *
 * QUIÉN DECIDE. El servidor no decide nada aquí: solo pinta el banner y le
 * pasa estos datos. Quien lee la elección y la aplica es el navegador. No es
 * un capricho: el service worker guarda una copia del inicio y se la sirve
 * igual a todo el mundo (TG-282). Si el servidor escondiera o mostrara el
 * banner según una cookie, la copia guardada le enseñaría a una persona la
 * decisión de otra.
 *
 * DÓNDE SE GUARDA. En una cookie propia sobre el dominio base y no en
 * localStorage, porque cada tienda puede vivir en su propio subdominio
 * (TG-232). Con localStorage, lo aceptado en la tienda no contaría en el
 * sitio principal y habría que aceptar dos veces.
 *
 * LA VERSIÓN. Si algún día entra otro tercero, sube VERSION: el
 * consentimiento viejo deja de valer y se vuelve a preguntar. Un permiso dado
 * para un mapa no es un permiso para lo que venga después.
 */
final class ConsentimientoCookies
{
    /** La cookie es propia y la escribe el navegador; el servidor nunca la lee. */
    public const COOKIE = 'fp_consentimiento';

    /** Súbela cuando cambien los terceros que se cargan. */
    public const VERSION = 1;

    /**
     * Seis meses.
     *
     * No hay un plazo obligatorio en la ley, así que se toma el más corto de
     * los que se usan de costumbre: volver a preguntar molesta menos que dar
     * por bueno para siempre un permiso que alguien dio una vez.
     */
    public const DIAS = 180;

    /**
     * 'necesarias' no se puede apagar: sin la cookie de sesión no hay forma de
     * iniciar sesión ni de llenar un carrito. Aparece en la lista para que en
     * el banner se vea qué sí se usa, no para ofrecer una elección falsa.
     */
    public const CATEGORIAS = ['necesarias', 'terceros'];

    /**
     * El dominio de la cookie: '.footwearpoint.app' para que la compartan el
     * sitio y las tiendas en subdominio. Vacío en local (127.0.0.1), donde el
     * navegador no acepta dominio y basta con dejarlo sin poner.
     */
    public static function dominio(): string
    {
        $base = (string) config('app.dominio_base');

        return $base !== '' ? '.'.$base : '';
    }
}
