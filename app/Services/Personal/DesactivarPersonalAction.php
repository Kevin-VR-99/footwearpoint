<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Distribuidora\RevocarAccesoUsuarioAction;
use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TG-338 (K40) — Desactiva o reactiva la cuenta completa (usuarios.estado).
 *
 * No toca las filas de personal: al reactivar todo vuelve igual. Al desactivar
 * se corta de verdad: cambia la llave de «recordarme», se borran los tokens y
 * celulares de la app, y 'solo.personal' saca la sesión abierta del panel.
 */
class DesactivarPersonalAction
{
    public function __construct(
        private ConfirmarPasswordAdminAction $confirmar,
        private SalvaguardasPersonal $salvaguardas,
        private RegistrarAuditoriaAction $auditoria,
        private RevocarAccesoUsuarioAction $revocar,
        private AvisarCambioPersonalAction $aviso,
    ) {
    }

    public function desactivar(Usuario $admin, Usuario $persona, string $password): string
    {
        $this->confirmar->exigir($admin, $password);
        PersonalPlataforma::exigirPersonal($persona);

        $cuenta = DB::transaction(function () use ($admin, $persona) {
            $cuenta = Usuario::query()->lockForUpdate()->findOrFail($persona->id);

            if ($cuenta->estado !== 'activo') {
                throw ValidationException::withMessages([SalvaguardasPersonal::CAMPO => ['Esta cuenta ya está desactivada.']]);
            }

            $this->salvaguardas->exigir($admin, $cuenta, 'desactivar');

            $anterior = $cuenta->estado;
            $cuenta->forceFill(['estado' => 'inactivo'])->setRememberToken(Str::random(60));
            $cuenta->save();

            $this->auditoria->ejecutar('personal.desactivado', 'usuario', (int) $cuenta->id, ['estado' => $anterior], ['estado' => 'inactivo']);

            return $cuenta;
        });

        $this->revocar->ejecutar((int) $cuenta->id);

        $fallo = $this->aviso->ejecutar($cuenta, 'Un administrador desactivó tu cuenta de FootwearPoint, así que ya no puedes entrar. Tu historial se conserva.');

        return $fallo ?? 'Cuenta desactivada. Se cerraron sus sesiones.';
    }

    public function reactivar(Usuario $admin, Usuario $persona, string $password): string
    {
        $this->confirmar->exigir($admin, $password);
        PersonalPlataforma::exigirPersonal($persona);

        $cuenta = DB::transaction(function () use ($persona) {
            $cuenta = Usuario::query()->lockForUpdate()->findOrFail($persona->id);

            if ($cuenta->estado === 'activo') {
                throw ValidationException::withMessages([SalvaguardasPersonal::CAMPO => ['Esta cuenta ya está activa.']]);
            }

            $anterior = $cuenta->estado;
            $cuenta->forceFill(['estado' => 'activo'])->save();

            $this->auditoria->ejecutar('personal.reactivado', 'usuario', (int) $cuenta->id, ['estado' => $anterior], ['estado' => 'activo']);

            return $cuenta;
        });

        $fallo = $this->aviso->ejecutar($cuenta, 'Un administrador reactivó tu cuenta de FootwearPoint. Ya puedes volver a entrar.');

        return $fallo ?? 'Cuenta reactivada.';
    }
}
