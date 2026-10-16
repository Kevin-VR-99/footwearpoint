<?php

namespace App\Services\Correo;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mandar un correo sin que se caiga la pantalla si el envío falla.
 *
 * El correo sale del servidor hacia afuera, y puede fallar por cosas que no
 * tienen nada que ver con lo que la persona acaba de hacer: el proveedor
 * caído, una dirección que rebota, la cuota del mes agotada, el dominio sin
 * verificar. Hasta ahora cualquiera de esas cosas se convertía en un
 * «Algo salió mal» de pantalla completa.
 *
 * Donde más dolía era al afiliar a alguien: la cuenta ya estaba creada y
 * guardada (el correo se manda con DB::afterCommit, o sea después de que todo
 * se guardó), así que la pantalla decía que había fallado cuando lo único que
 * faltó fue el correo. Quien lo usaba no tenía forma de saber si el cliente
 * había quedado registrado o no.
 *
 * Esto NO esconde el problema: el fallo queda en el log con su contexto, y a
 * quien está en el mostrador se le dice en palabras suyas que el correo no
 * salió, para que use «Reenviar confirmación» o la contraseña temporal.
 */
class IntentarEnviarCorreoAction
{
    public const AVISO = 'No pudimos mandar el correo en este momento. '
        .'El error quedó anotado: inténtalo de nuevo en unos minutos, '
        .'o dale una contraseña temporal mientras tanto.';

    /**
     * Corre el envío. Devuelve null si salió, o el aviso que hay que mostrar
     * si falló.
     *
     * @param  callable  $envio         Lo que manda el correo.
     * @param  string    $queSeMandaba  Para la bitácora: «confirmación de correo», etc.
     * @param  array     $contexto      Datos para poder rastrearlo en el log.
     */
    public function ejecutar(callable $envio, string $queSeMandaba, array $contexto = []): ?string
    {
        try {
            $envio();

            return null;
        } catch (Throwable $e) {
            // Se guarda la cadena completa a proposito: un fallo de correo casi
            // nunca dice lo importante en la excepcion de arriba. Un rechazo
            // del servidor (535, 550) y una conexion que nunca se abrio salen
            // las dos como TransportException, y lo que las distingue esta en
            // la causa de adentro.
            Log::error('No se pudo enviar el correo de '.$queSeMandaba, $contexto + [
                'excepcion' => $e::class,
                'mensaje'   => $e->getMessage(),
                'causas'    => $this->cadenaDeCausas($e),
                'en'        => $e->getFile().':'.$e->getLine(),
            ]);

            return self::AVISO;
        }
    }

    /** @return list<string> */
    private function cadenaDeCausas(Throwable $e): array
    {
        $causas = [];

        while ($e = $e->getPrevious()) {
            $causas[] = $e::class.': '.$e->getMessage();
        }

        return $causas;
    }
}
