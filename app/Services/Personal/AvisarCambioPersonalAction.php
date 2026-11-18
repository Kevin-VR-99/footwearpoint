<?php

namespace App\Services\Personal;

use App\Mail\CambioCuentaPersonalMail;
use App\Models\Usuario;
use App\Services\Correo\IntentarEnviarCorreoAction;
use Illuminate\Support\Facades\Mail;

/** TG-337: avisa a la persona. Si el correo falla, el cambio ya hecho se queda. */
class AvisarCambioPersonalAction
{
    public function __construct(private IntentarEnviarCorreoAction $correo)
    {
    }

    /** @return string|null El aviso para quien hizo el cambio, si no salió. */
    public function ejecutar(Usuario $persona, string $mensaje): ?string
    {
        $fallo = $this->correo->ejecutar(
            fn () => Mail::to((string) $persona->email)->send(new CambioCuentaPersonalMail($persona, $mensaje)),
            'aviso_cambio_personal',
            ['usuario_id' => $persona->id],
        );

        return $fallo === null ? null : 'El cambio quedó hecho, pero no se pudo avisar a la persona por correo.';
    }
}
