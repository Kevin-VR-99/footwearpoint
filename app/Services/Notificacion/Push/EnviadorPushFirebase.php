<?php

namespace App\Services\Notificacion\Push;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Envío real por Firebase Cloud Messaging (TG-135).
 *
 * Necesita la llave de la cuenta de servicio en la ruta de
 * FIREBASE_CREDENTIALS (storage/app/firebase/credenciales.json). Esa llave no
 * está en el repo: se pasa por privado.
 */
class EnviadorPushFirebase implements EnviadorPush
{
    public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
    {
        // Firebase se resuelve aquí adentro y no en el constructor, a
        // propósito: si alguien del equipo no tiene la llave, el error sale
        // al intentar enviar (y quien llama lo atrapa), no al construir la
        // clase, que tumbaría el cambio de estado del pedido.
        $messaging = app(Messaging::class);

        $reporte = $messaging->sendMulticast(
            CloudMessage::fromArray([
                'notification' => ['title' => $titulo, 'body' => $mensaje],
                'data'         => $datos,
            ]),
            $tokens,
        );

        return array_values(array_unique(array_merge(
            $reporte->invalidTokens(),
            $reporte->unknownTokens(),
        )));
    }
}
