<?php

namespace App\Services\Auth;

use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
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

    /**
     * TG-243: lo que ve un empleado al que su distribuidora desactivo, en el
     * login y al sacarlo de una sesion abierta. Antes veia MENSAJE_SOLO_PERSONAL,
     * que le decia que entrara por la app como si fuera cliente.
     */
    public const MENSAJE_CUENTA_DESACTIVADA = 'Tu cuenta de personal está desactivada, así que no puedes entrar al panel. '
        .'Comunícate con el administrador de tu distribuidora para que la reactive.';

    /** TG-196 (G5): lo que ve el personal de una distribuidora suspendida. */
    public const MENSAJE_DISTRIBUIDORA_SUSPENDIDA = 'Tu distribuidora está suspendida, así que por ahora no puede usar '
        .'FootwearPoint. Tu información se conserva. Comunícate con FootwearPoint para reactivarla.';

    /** TG-195 (G4): lo que ve el personal de una distribuidora rechazada. */
    public const MENSAJE_DISTRIBUIDORA_RECHAZADA = 'La solicitud de tu distribuidora fue rechazada, '
        .'así que no puede usar FootwearPoint.';

    /** TG-220 (E2-08): lo que ve el personal de una distribuidora pendiente. */
    public const MENSAJE_DISTRIBUIDORA_PENDIENTE = 'Tu solicitud está en revisión. '
        .'Te avisaremos por correo cuando sea aprobada.';

    /**
     * TG-195 (G4) — Por qué esta persona no puede entrar al panel, o null si
     * sí puede. Lo usan el login web y el middleware SoloPersonalPanel.
     *
     *  - Es personal, pero desactivado         -> MENSAJE_CUENTA_DESACTIVADA (TG-243).
     *  - No es personal (revendedor, cliente)  -> MENSAJE_SOLO_PERSONAL.
     *  - Su distribuidora fue rechazada        -> mensaje con el motivo, para
     *    que sepa qué le faltó (E2-01: una rechazada no puede operar).
     *  - Su distribuidora está suspendida      -> MENSAJE_DISTRIBUIDORA_SUSPENDIDA
     *    (TG-196, E2-02: no puede operar, pero conserva su información).
     *  - Su distribuidora está pendiente       -> MENSAJE_DISTRIBUIDORA_PENDIENTE
     *    (TG-220, E2-08). Antes sí entraba, porque solo el admin general podía
     *    crearla y ya la había revisado. Con el formulario público eso dejaría
     *    entrar a cualquiera sin aprobación.
     */
    public function motivoSinAcceso(Usuario $usuario): ?string
    {
        $staff = $this->staffActivo($usuario);

        if ($staff === null) {
            if ($this->esAdminGeneral($usuario)) {
                return null;
            }

            return $this->esPersonalDesactivado($usuario)
                ? self::MENSAJE_CUENTA_DESACTIVADA
                : self::MENSAJE_SOLO_PERSONAL;
        }

        $distribuidora = $staff->distribuidora;

        if ($distribuidora !== null && ! $distribuidora->puedeOperar()) {
            return match ($distribuidora->estado) {
                'rechazada' => self::mensajeDistribuidoraRechazada($distribuidora->motivo_rechazo),
                'pendiente' => self::MENSAJE_DISTRIBUIDORA_PENDIENTE,
                default => self::MENSAJE_DISTRIBUIDORA_SUSPENDIDA,
            };
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

    /**
     * TG-243 — True si la persona fue personal de una distribuidora y la
     * desactivaron (tiene registro de staff, pero ninguno activo).
     *
     * Aqui no hay distribuidora resuelta (en el login no hay sesion, y al
     * expulsarlo Tenant::id() ya no la encuentra), asi que el TenantScope
     * devolveria cero filas. Primero se ubica de que distribuidora era con
     * una consulta a la tabla, y luego se confirma con el modelo dentro de
     * Tenant::forzar, sin quitar los scopes globales.
     */
    public function esPersonalDesactivado(Usuario $usuario): bool
    {
        $distribuidoraIds = DB::table('distribuidora_staff')
            ->where('usuario_id', $usuario->id)
            ->where('estado', '!=', 'activo')
            ->pluck('distribuidora_id');

        foreach ($distribuidoraIds as $distribuidoraId) {
            $desactivado = Tenant::forzar((int) $distribuidoraId, fn () => DistribuidoraStaff::query()
                ->where('usuario_id', $usuario->id)
                ->where('estado', '!=', 'activo')
                ->exists());

            if ($desactivado) {
                return true;
            }
        }

        return false;
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
