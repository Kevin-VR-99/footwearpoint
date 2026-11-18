<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Rules\CorreoConDominioReal;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Perfil\CambioContactoAction;
use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\Validator;

/**
 * TG-337 (K39) — El admin general pide cambiar el correo de una persona.
 * Se aplica cuando lo confirman desde el correo nuevo; el anterior recibe aviso.
 */
class CambiarCorreoPersonalAction
{
    public function __construct(
        private ConfirmarPasswordAdminAction $confirmar,
        private CambioContactoAction $contacto,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    public function ejecutar(Usuario $admin, Usuario $persona, string $email, string $password): string
    {
        $this->confirmar->exigir($admin, $password);
        PersonalPlataforma::exigirPersonal($persona);

        $email = trim($email);

        Validator::make(['email' => $email], [
            'email' => ['required', 'email', new CorreoConDominioReal(), 'max:190'],
        ], [
            'email.required' => 'Escribe el correo nuevo.',
            'email.email' => 'El correo no es válido.',
            'email.max' => 'El correo no puede pasar de 190 caracteres.',
        ])->validate();

        $anterior = (string) $persona->email;
        $mensaje = $this->contacto->solicitarCorreoDePersonal($persona, $email);

        $this->auditoria->sinDistribuidora(
            'personal.correo_solicitado',
            'usuario',
            (int) $persona->id,
            ['email' => $anterior],
            ['email' => mb_strtolower($email)],
        );

        return $mensaje;
    }
}
