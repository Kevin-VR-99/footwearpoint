<?php

namespace App\Services\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Usuario;

/**
 * E16-03 (TG-136) — Los celulares a los que se les manda notificación push.
 *
 * Firebase le da a cada instalación de la app un token. Aquí se guarda ligado
 * al usuario que inició sesión en ese celular, y se quita al cerrar sesión.
 * El envío real de las notificaciones es otra tarea (TG-135) y lee de esta
 * misma tabla.
 */
class GestionarDispositivoFcmAction
{
    /**
     * Se busca por token, no por usuario, a propósito.
     *
     * El token identifica al celular, no a la persona. Si María cerró sesión
     * sin avisar (por ejemplo, borró los datos de la app) y luego José entra
     * en ese mismo celular, el token tiene que pasar a José. Si se quedara
     * ligado a los dos, a José le llegarían los avisos de los pedidos de María.
     *
     * Registrar el mismo token otra vez no duplica nada: solo actualiza cuándo
     * se usó por última vez. Un usuario sí puede tener varios celulares.
     *
     * TG-144: también se guarda la sesión de Sanctum con la que se registró.
     * Cuando esa sesión se revoca, la base borra este celular sola. Si el
     * mismo celular vuelve a iniciar sesión, al registrarse otra vez queda
     * ligado a la sesión nueva.
     */
    public function registrar(Usuario $usuario, string $token, string $plataforma, ?int $sesionId): DispositivoFcm
    {
        return DispositivoFcm::updateOrCreate(
            ['token' => $token],
            [
                'usuario_id'               => $usuario->id,
                'personal_access_token_id' => $sesionId,
                'plataforma'               => $plataforma,
                'ultimo_uso_at'            => now(),
            ],
        );
    }

    /**
     * Solo quita el celular si es de ese usuario: con el token de otra
     * persona no pasa nada.
     */
    public function quitar(Usuario $usuario, string $token): void
    {
        DispositivoFcm::where('usuario_id', $usuario->id)
            ->where('token', $token)
            ->delete();
    }
}
