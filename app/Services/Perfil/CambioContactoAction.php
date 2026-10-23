<?php

namespace App\Services\Perfil;

use App\Exceptions\OperacionInvalidaException;
use App\Mail\AvisoCambioCorreoMail;
use App\Mail\ConfirmarCambioContactoMail;
use App\Models\CambioContactoPendiente;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Correo\IntentarEnviarCorreoAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * El cliente mayorista cambia su correo o su teléfono desde la app.
 *
 * Nada se aplica al pedirlo: se guarda como pendiente y se manda un enlace
 * firmado que vence (MINUTOS). Solo al abrirlo se aplica.
 *
 * - Correo: el enlace va al correo NUEVO (así se comprueba que es suyo) y al
 *   viejo le llega un aviso, por si no fue él.
 * - Teléfono: el enlace va a su correo actual.
 *
 * Pedir otro cambio del mismo tipo anula el anterior.
 */
class CambioContactoAction
{
    public const MINUTOS = 60;

    public const MENSAJE_USA_CONFIRMACION = 'Para cambiar tu teléfono usa «Cambiar teléfono»: te mandamos un enlace a tu correo para confirmarlo.';

    public function __construct(
        private IntentarEnviarCorreoAction $correo,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    public function solicitarCorreo(Usuario $usuario, string $email): string
    {
        $this->exigirRevendedor($usuario);
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower((string) $usuario->email)) {
            throw ValidationException::withMessages(['email' => ['Ese ya es tu correo.']]);
        }

        if (Usuario::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['Ese correo ya lo usa otra cuenta.']]);
        }

        $cambio = $this->crear($usuario, 'email', (string) $usuario->email, $email);
        $url = $this->enlace($cambio);

        $fallo = $this->correo->ejecutar(
            fn () => Mail::to($email)->send(new ConfirmarCambioContactoMail($usuario, $cambio, $url)),
            'cambio_correo',
            ['usuario_id' => $usuario->id, 'tipo' => 'email'],
        );

        if ($fallo !== null) {
            $cambio->delete();

            throw ValidationException::withMessages(['email' => [$fallo]]);
        }

        // El aviso al correo viejo es informativo: si falla, el cambio sigue.
        $this->correo->ejecutar(
            fn () => Mail::to((string) $usuario->email)->send(new AvisoCambioCorreoMail($usuario, $email)),
            'aviso_cambio_correo',
            ['usuario_id' => $usuario->id],
        );

        return 'Te mandamos un enlace a '.$email.'. Ábrelo en la próxima hora para confirmar el cambio; mientras tanto sigues entrando con tu correo actual.';
    }

    public function solicitarTelefono(Usuario $usuario, string $telefono): string
    {
        $contacto = $this->exigirRevendedor($usuario);

        if ($telefono === (string) $contacto->telefono) {
            throw ValidationException::withMessages(['telefono' => ['Ese ya es tu teléfono.']]);
        }

        $cambio = $this->crear($usuario, 'telefono', $contacto->telefono, $telefono);
        $url = $this->enlace($cambio);

        $fallo = $this->correo->ejecutar(
            fn () => Mail::to((string) $usuario->email)->send(new ConfirmarCambioContactoMail($usuario, $cambio, $url)),
            'cambio_telefono',
            ['usuario_id' => $usuario->id, 'tipo' => 'telefono'],
        );

        if ($fallo !== null) {
            $cambio->delete();

            throw ValidationException::withMessages(['telefono' => [$fallo]]);
        }

        return 'Te mandamos un enlace a '.$usuario->email.'. Ábrelo en la próxima hora para confirmar tu teléfono nuevo.';
    }

    /**
     * Aplica el cambio del enlace. Regresa 'listo', 'ya_estaba', 'vencido'
     * o 'ocupado' (otro ya tomó ese correo mientras tanto).
     */
    public function confirmar(CambioContactoPendiente $cambio): string
    {
        return DB::transaction(function () use ($cambio) {
            $cambio = CambioContactoPendiente::query()->lockForUpdate()->findOrFail($cambio->id);

            if ($cambio->confirmado_en !== null) {
                return 'ya_estaba';
            }

            if (! $cambio->vigente()) {
                return 'vencido';
            }

            $usuario = Usuario::withoutGlobalScopes()->findOrFail($cambio->usuario_id);

            if ($cambio->tipo === 'email') {
                $ocupado = Usuario::withoutGlobalScopes()
                    ->where('email', $cambio->valor_nuevo)
                    ->whereKeyNot($usuario->id)
                    ->exists();

                if ($ocupado) {
                    return 'ocupado';
                }

                // Lo acaba de abrir desde ese buzón: queda confirmado.
                $usuario->forceFill([
                    'email'             => $cambio->valor_nuevo,
                    'email_verified_at' => now(),
                ])->save();
            } else {
                $contacto = Revendedor::withoutGlobalScopes()->where('usuario_id', $usuario->id)->first();

                if (! $contacto) {
                    return 'vencido';
                }

                $contacto->forceFill(['telefono' => $cambio->valor_nuevo])->save();
            }

            $cambio->forceFill(['confirmado_en' => now()])->save();

            $this->auditoria->ejecutar(
                $cambio->tipo === 'email' ? 'perfil.correo_cambiado' : 'perfil.telefono_cambiado',
                'usuario',
                (int) $usuario->id,
                [$cambio->tipo => $cambio->valor_anterior],
                [$cambio->tipo => $cambio->valor_nuevo],
            );

            return 'listo';
        });
    }

    /** Para GET /api/perfil: qué cambios esperan confirmación. */
    public function pendientesDe(Usuario $usuario): array
    {
        return CambioContactoPendiente::query()
            ->where('usuario_id', $usuario->id)
            ->whereNull('confirmado_en')
            ->where('expira_en', '>', now())
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (CambioContactoPendiente $c) => [$c->tipo => $c->valor_nuevo])
            ->all();
    }

    private function crear(Usuario $usuario, string $tipo, ?string $anterior, string $nuevo): CambioContactoPendiente
    {
        return DB::transaction(function () use ($usuario, $tipo, $anterior, $nuevo) {
            CambioContactoPendiente::query()
                ->where('usuario_id', $usuario->id)
                ->where('tipo', $tipo)
                ->whereNull('confirmado_en')
                ->delete();

            return CambioContactoPendiente::create([
                'usuario_id'     => $usuario->id,
                'tipo'           => $tipo,
                'valor_anterior' => $anterior,
                'valor_nuevo'    => $nuevo,
                'expira_en'      => now()->addMinutes(self::MINUTOS),
            ]);
        });
    }

    private function enlace(CambioContactoPendiente $cambio): string
    {
        return URL::temporarySignedRoute(
            'perfil.confirmar-cambio',
            $cambio->expira_en,
            ['cambio' => $cambio->id],
        );
    }

    private function exigirRevendedor(Usuario $usuario): Revendedor
    {
        $contacto = $usuario->contacto();

        if (! $contacto instanceof Revendedor) {
            throw new OperacionInvalidaException('Solo los clientes mayoristas pueden cambiar su correo o teléfono desde la app. Pídeselo a tu distribuidora.', 403);
        }

        return $contacto;
    }
}
