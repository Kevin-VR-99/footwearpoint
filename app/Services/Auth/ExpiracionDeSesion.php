<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * TG-187 (A5) — Expiracion de las sesiones de la app.
 *
 * Hasta ahora el token de la app no caducaba nunca: uno emitido hace meses
 * seguia abriendo la API. El criterio del backlog (E1-01) pide que la sesion
 * expire "tras un periodo de inactividad configurable".
 *
 * Se llevan dos relojes, porque resuelven cosas distintas:
 *
 *  1. INACTIVIDAD: si la app no habla con el servidor durante tanto tiempo,
 *     la sesion se vence. Cada peticion reinicia el reloj. Es el que protege
 *     el celular que alguien perdio y dejo de usar.
 *  2. ANTIGUEDAD (tope absoluto): aunque la use a diario, el token no vive
 *     para siempre. Es el que limita a un token copiado.
 *
 * Los dos periodos se configuran en config/sanctum.php (y desde el .env), y
 * se leen en cada peticion: cambiar el valor no obliga a reiniciar nada.
 *
 * Quien llama a esta clase es el gancho de Sanctum que registra
 * AppServiceProvider. Va ahi, y no en un middleware, por el orden: el gancho
 * corre ANTES de que Sanctum escriba last_used_at = ahora. Un middleware
 * tendria que preguntar por el usuario para saber de quien es el token, y
 * eso ya habria refrescado la fecha, asi que la inactividad nunca se veria.
 */
class ExpiracionDeSesion
{
    public const POR_INACTIVIDAD = 'inactividad';

    public const POR_ANTIGUEDAD = 'antiguedad';

    /**
     * Lo que responde el gancho de Sanctum: ¿este token sigue sirviendo?
     *
     * $validoParaSanctum es el veredicto que trae Sanctum (tope absoluto,
     * expires_at del token y proveedor). Se respeta tal cual; aqui solo se
     * agrega la inactividad, que Sanctum no revisa.
     *
     * El token que ya murio se borra: si no, se queda en la tabla
     * respondiendo 401 para siempre, y el celular viejo de un revendedor
     * dejaria basura ahi hasta que alguien la limpiara a mano.
     */
    public function sigueValido(PersonalAccessToken $token, bool $validoParaSanctum): bool
    {
        if ($this->motivo($token) !== null) {
            $token->delete();

            return false;
        }

        return $validoParaSanctum;
    }

    /** Por que esta vencido, o null si todavia sirve. */
    public function motivo(PersonalAccessToken $token): ?string
    {
        // Los dos periodos se pueden apagar con 0, y entonces no se revisan.
        $inactividad = $this->minutosDeInactividad();

        if ($inactividad > 0 && $this->sinUsarDesde($token)->lt(now()->subMinutes($inactividad))) {
            return self::POR_INACTIVIDAD;
        }

        $vida = $this->minutosDeVida();

        if ($vida > 0 && $token->created_at && $token->created_at->lt(now()->subMinutes($vida))) {
            return self::POR_ANTIGUEDAD;
        }

        return null;
    }

    /** Minutos que puede pasar sin usarse. 0 = no se revisa. */
    public function minutosDeInactividad(): int
    {
        return (int) config('sanctum.inactividad_minutos', 0);
    }

    /** Minutos de vida desde que se creo. 0 = no caduca. */
    public function minutosDeVida(): int
    {
        return (int) config('sanctum.expiration', 0);
    }

    /**
     * Desde cuando no se usa. Un token recien creado todavia no tiene
     * last_used_at (Sanctum lo escribe en la PRIMERA peticion), asi que
     * cuenta desde que se creo; si tampoco hubiera fecha, se toma "ahora"
     * para no vencer una sesion por falta de datos.
     */
    private function sinUsarDesde(PersonalAccessToken $token): Carbon
    {
        return $token->last_used_at ?? $token->created_at ?? now();
    }
}
