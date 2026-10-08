<?php

namespace App\Services\Auth;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use Spatie\Permission\PermissionRegistrar;

/**
 * TG-184 (A1) — Quien puede entrar al panel web.
 *
 * El panel web es del PERSONAL: el admin general y el personal de una
 * distribuidora (admin de distribuidora y empleados). Los revendedores y los
 * clientes directos entran por la app movil, que tiene su propio login
 * (AuthController::login ya los separa con ROLES_SOLO_WEB).
 *
 * Hasta ahora el login web no revisaba nada de esto: cualquiera con cuenta
 * entraba y llegaba al panel de la distribuidora. En el recorrido del equipo
 * (29-sep) se comprobo entrando con un revendedor.
 *
 * La regla vive aqui, en un solo lugar, porque la usan el login web y el
 * middleware que cuida las rutas del panel.
 */
class AccesoPanelWebService
{
    /** Lo que se le dice a quien no es personal, en el login y al sacarlo. */
    public const MENSAJE_SOLO_PERSONAL = 'Este panel es solo para el personal de la distribuidora. '
        .'Si eres cliente o cliente mayorista, entra desde la app FootwearPoint.';

    /** TG-195 (G4): lo que ve el personal de una distribuidora rechazada. */
    public const MENSAJE_DISTRIBUIDORA_RECHAZADA = 'La solicitud de tu distribuidora fue rechazada, '
        .'así que no puede usar FootwearPoint.';

    /**
     * TG-195 (G4) — Por qué esta persona no puede entrar al panel, o null si
     * sí puede. Lo usan el login web y el middleware SoloPersonalPanel.
     *
     *  - No es personal (revendedor, cliente)  -> MENSAJE_SOLO_PERSONAL.
     *  - Su distribuidora fue rechazada        -> mensaje con el motivo, para
     *    que sepa qué le faltó (E2-01: una rechazada no puede operar).
     *
     * Pendientes y suspendidas siguen entrando como hasta ahora.
     */
    public function motivoSinAcceso(Usuario $usuario): ?string
    {
        $staff = $this->staffActivo($usuario);

        if ($staff === null) {
            return $this->esAdminGeneral($usuario) ? null : self::MENSAJE_SOLO_PERSONAL;
        }

        $distribuidora = $staff->distribuidora;

        if ($distribuidora !== null && in_array($distribuidora->estado, Distribuidora::ESTADOS_SIN_OPERACION, true)) {
            return self::mensajeDistribuidoraRechazada($distribuidora->motivo_rechazo);
        }

        return null;
    }

    /** El motivo va como texto: las vistas lo escapan al mostrarlo. */
    public static function mensajeDistribuidoraRechazada(?string $motivo): string
    {
        $motivo = trim((string) $motivo);

        return self::MENSAJE_DISTRIBUIDORA_RECHAZADA.($motivo !== '' ? ' Motivo: '.$motivo : '');
    }

    /** True si la persona es personal: admin general o staff activo. */
    public function esPersonal(Usuario $usuario): bool
    {
        return $this->staffActivo($usuario) !== null || $this->esAdminGeneral($usuario);
    }

    /**
     * Su registro de personal, solo si esta activo.
     *
     * Se consulta sin el scope de distribuidora a proposito: esto corre en el
     * login, cuando todavia no se sabe de que distribuidora es la persona.
     *
     * Importante: se exige estado 'activo'. Antes, el login web buscaba el
     * staff SIN mirar su estado, asi que un empleado desactivado seguia
     * tomando el contexto de su distribuidora.
     */
    public function staffActivo(Usuario $usuario): ?DistribuidoraStaff
    {
        return DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activo')
            ->first();
    }

    /** El admin general no es staff de ninguna distribuidora: su rol vive en el equipo 0. */
    public function esAdminGeneral(Usuario $usuario): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);

        // Sin esto se reusarian los roles ya cargados de otro equipo.
        $usuario->unsetRelation('roles');

        return $usuario->hasRole('admin_general');
    }
}
