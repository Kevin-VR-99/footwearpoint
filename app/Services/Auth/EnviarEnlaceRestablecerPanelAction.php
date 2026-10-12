<?php

namespace App\Services\Auth;

use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * TG-192 (A3) — El personal le manda el enlace de restablecimiento a un
 * revendedor o a un cliente desde el panel.
 *
 * Hoy, si alguien de la app olvida su contrasena, se queda fuera: el panel no
 * tiene forma de ayudarlo y la pantalla publica de "olvide mi contrasena"
 * depende del correo del usuario.
 *
 * Esta accion NO es la misma que EnviarEnlaceRecuperacionAction: aquella es
 * la publica y siempre responde lo mismo, exista o no el correo, para que
 * nadie pueda tantear cuentas. Aqui es al reves: quien lo pide ya es personal
 * de la distribuidora y necesita saber que paso de verdad (si se mando, o si
 * hay que esperar porque se pidio hace muy poco).
 */
class EnviarEnlaceRestablecerPanelAction
{
    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /**
     * Manda el enlace a la cuenta de esa persona y regresa el aviso que se le
     * muestra al personal.
     *
     * @param  Usuario  $cuenta        La cuenta de acceso del revendedor o cliente.
     * @param  string   $entidadTipo   'revendedor' o 'cliente_directo', para la bitacora.
     * @param  int      $entidadId     Id del contacto, para poder rastrearlo.
     */
    public function ejecutar(Usuario $cuenta, string $entidadTipo, int $entidadId): string
    {
        $estado = Password::broker('users')->sendResetLink(['email' => $cuenta->email]);

        if ($estado === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'enlace' => ['Ya se envió un enlace hace poco. Espera unos minutos antes de volver a intentarlo.'],
            ]);
        }

        if ($estado !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'enlace' => ['No se pudo enviar el enlace. Revisa que la cuenta tenga un correo válido.'],
            ]);
        }

        // Mandar un enlace de restablecimiento es una accion sensible: queda
        // en la bitacora quien lo pidio, para quien y cuando (E17-03).
        $this->auditoria->ejecutar(
            accion: 'password.enlace_restablecimiento',
            entidadTipo: $entidadTipo,
            entidadId: $entidadId,
            datosNuevos: ['email' => $cuenta->email],
        );

        return 'Listo. Le enviamos el enlace a '.$cuenta->email.'. Vence en 60 minutos.';
    }
}
