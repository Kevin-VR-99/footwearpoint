<?php

namespace App\Services\Personal;

use App\Models\CajaSesion;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Support\PersonalPlataforma;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-337 (K39) — Cambia una sola asignación de personal (un rol en un equipo).
 *
 * Nunca toca las demás asignaciones ni los roles de cliente, y no mueve a nadie
 * de distribuidora: las filas de personal están ligadas al historial de pedidos,
 * pagos, ventas, vales y cajas.
 *
 * Admin general y personal activo no conviven: Tenant resuelve primero la
 * distribuidora y SetTenantTeam dejaría a esa cuenta sin /admin.
 */
class CambiarRolPersonalAction
{
    private const ADMIN = ProvisionarRolesDistribuidoraAction::ROL_ADMINISTRADOR;

    public function __construct(
        private ConfirmarPasswordAdminAction $confirmar,
        private ProvisionarRolesDistribuidoraAction $roles,
        private RegistrarAuditoriaAction $auditoria,
        private AvisarCambioPersonalAction $aviso,
    ) {
    }

    /**
     * @param  array{asignacion?: mixed, rol_nuevo?: mixed, distribuidora_nueva?: mixed, confirmar_consecuencias?: mixed, password_confirmacion?: mixed}  $datos
     * @return string El mensaje para quien hizo el cambio.
     *
     * @throws ValidationException con el motivo en el campo que corresponde.
     */
    public function ejecutar(Usuario $admin, Usuario $persona, array $datos): string
    {
        $this->confirmar->exigir($admin, (string) ($datos['password_confirmacion'] ?? ''));
        PersonalPlataforma::exigirPersonal($persona);

        $rolNuevo = (string) ($datos['rol_nuevo'] ?? '');

        if (! isset(PersonalPlataforma::ROLES[$rolNuevo])) {
            $this->rechazar('Elige un rol de personal.');
        }

        $cambio = DB::transaction(function () use ($admin, $persona, $datos, $rolNuevo) {
            $cambio = $this->aplicar($admin, $persona, $datos, $rolNuevo);

            $this->auditoria->ejecutar('personal.rol_cambiado', 'usuario', (int) $persona->id, $cambio['antes'], $cambio['despues']);

            return $cambio;
        });

        $fallo = $this->aviso->ejecutar($persona, 'Tu rol en FootwearPoint cambió de «'.$this->describir($cambio['antes'])
            .'» a «'.$this->describir($cambio['despues']).'».');

        return $fallo ?? 'Rol actualizado.';
    }

    private function aplicar(Usuario $admin, Usuario $persona, array $datos, string $rolNuevo): array
    {
        $actual = $this->asignacionElegida($persona, (string) ($datos['asignacion'] ?? ''));

        if ($actual['rol'] === $rolNuevo) {
            $this->rechazar('Ya tiene ese rol.');
        }

        $general = PersonalPlataforma::ADMIN_GENERAL;

        if ($actual['rol'] !== $general && $rolNuevo !== $general) {
            return $this->cambiarEnSuDistribuidora($persona, $actual, $rolNuevo);
        }

        if (! filter_var($datos['confirmar_consecuencias'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'confirmar_consecuencias' => ['Confirma que entiendes las consecuencias de este cambio.'],
            ]);
        }

        return $actual['rol'] === $general
            ? $this->deAdminGeneralAPersonal($admin, $persona, $rolNuevo, $datos['distribuidora_nueva'] ?? null)
            : $this->dePersonalAAdminGeneral($persona, $actual);
    }

    private function cambiarEnSuDistribuidora(Usuario $persona, array $actual, string $rolNuevo): array
    {
        $distribuidora = Distribuidora::query()->findOrFail($actual['equipo']);
        $fila = $this->filaDe($persona, (int) $distribuidora->id);

        if ($actual['rol'] === self::ADMIN) {
            $this->exigirOtroAdmin($persona, $distribuidora, $fila);
        }

        $this->roles->quitar($persona, $actual['rol'], (int) $distribuidora->id);
        $this->darRolDeDistribuidora($persona, $rolNuevo, $distribuidora);

        // Los permisos finos no se tocan: regresan si vuelve a ser empleado.
        $this->guardarFila($persona, $distribuidora, $fila, $rolNuevo, $fila?->estado ?? 'activo');

        return $this->resumen($actual['rol'], $distribuidora, $rolNuevo, $distribuidora);
    }

    private function deAdminGeneralAPersonal(Usuario $admin, Usuario $persona, string $rolNuevo, mixed $distribuidoraId): array
    {
        if ((int) $persona->id === (int) $admin->id) {
            $this->rechazar('No puedes quitarte tu propio rol de admin general. Otro admin general debe hacerlo.');
        }

        $otroActivo = Usuario::query()
            ->whereKey(PersonalPlataforma::adminsGeneralesBloqueados())
            ->whereKeyNot($persona->id)
            ->where('estado', 'activo')
            ->exists();

        if (! $otroActivo) {
            $this->rechazar('Es el último admin general activo. Primero nombra a otro.');
        }

        $distribuidora = ctype_digit((string) $distribuidoraId)
            ? Distribuidora::query()->whereKey((int) $distribuidoraId)->where('estado', 'activa')->first()
            : null;

        if ($distribuidora === null) {
            throw ValidationException::withMessages(['distribuidora_nueva' => ['Elige una distribuidora activa.']]);
        }

        $this->exigirSinOtraDistribuidora($persona, (int) $distribuidora->id);

        // Único por distribuidora y usuario: si ya estuvo ahí, se reactiva.
        $fila = $this->filaDe($persona, (int) $distribuidora->id);

        $this->roles->ejecutar($distribuidora);
        $this->roles->quitar($persona, PersonalPlataforma::ADMIN_GENERAL, 0);
        $this->darRolDeDistribuidora($persona, $rolNuevo, $distribuidora);
        $this->guardarFila($persona, $distribuidora, $fila, $rolNuevo, 'activo');

        return $this->resumen(PersonalPlataforma::ADMIN_GENERAL, null, $rolNuevo, $distribuidora);
    }

    private function dePersonalAAdminGeneral(Usuario $persona, array $actual): array
    {
        $distribuidora = Distribuidora::query()->findOrFail($actual['equipo']);
        $fila = $this->filaDe($persona, (int) $distribuidora->id);

        if (count(PersonalPlataforma::rolesDe([(int) $persona->id])[$persona->id] ?? []) !== 1) {
            $this->rechazar('Solo se puede hacer con una persona que tenga una sola asignación.');
        }

        $this->exigirSinOtraDistribuidora($persona, (int) $distribuidora->id);

        // Aunque el registro esté inactivo: Tenant resuelve también por ahí y,
        // si se reactivara, esta cuenta perdería /admin.
        if ($this->esCliente($persona)) {
            $this->rechazar('Esta cuenta también es cliente en alguna distribuidora, y como admin general perdería el acceso a la administración.');
        }

        if ($fila !== null && CajaSesion::withoutGlobalScopes()
            ->where('abierta_por_staff_id', $fila->id)
            ->where('estado', 'abierta')
            ->exists()) {
            $this->rechazar('Tiene una caja abierta. Primero debe hacer su corte.');
        }

        if ($actual['rol'] === self::ADMIN) {
            $this->exigirOtroAdmin($persona, $distribuidora, $fila);
        }

        $this->roles->quitar($persona, $actual['rol'], (int) $distribuidora->id);
        $this->roles->asignarAdminGeneral($persona);

        if ($fila !== null && $fila->estado !== 'inactivo') {
            $fila->forceFill(['estado' => 'inactivo'])->save();
        }

        $cambio = $this->resumen($actual['rol'], $distribuidora, PersonalPlataforma::ADMIN_GENERAL, null);

        // El aviso de privacidad no declara la dirección del admin general. En
        // la auditoría queda solo que se borró, nunca el valor.
        if (Usuario::query()->whereKey($persona->id)->whereNotNull('direccion')->exists()) {
            $cambio['despues']['direccion_borrada'] = true;
        }

        $persona->forceFill(['direccion' => null])->save();

        return $cambio;
    }

    /** @return array{rol: string, equipo: int} */
    private function asignacionElegida(Usuario $persona, string $valor): array
    {
        foreach (PersonalPlataforma::rolesDe([(int) $persona->id])[$persona->id] ?? [] as $asignacion) {
            if ($asignacion['rol'].'|'.$asignacion['equipo'] === $valor) {
                return $asignacion;
            }
        }

        throw ValidationException::withMessages(['asignacion' => ['Elige la asignación que quieres cambiar.']]);
    }

    private function filaDe(Usuario $persona, int $distribuidoraId): ?DistribuidoraStaff
    {
        return DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('usuario_id', $persona->id)
            ->lockForUpdate()
            ->first();
    }

    private function guardarFila(Usuario $persona, Distribuidora $distribuidora, ?DistribuidoraStaff $fila, string $rol, string $estado): void
    {
        $tipo = $rol === self::ADMIN ? 'administrador' : 'empleado';

        if ($fila !== null) {
            $fila->forceFill(['tipo' => $tipo, 'estado' => $estado])->save();

            return;
        }

        Tenant::forzar((int) $distribuidora->id, fn () => DistribuidoraStaff::create([
            'distribuidora_id' => $distribuidora->id,
            'usuario_id' => $persona->id,
            'tipo' => $tipo,
            'estado' => $estado,
            'fecha_alta' => now(),
        ]));
    }

    private function darRolDeDistribuidora(Usuario $persona, string $rol, Distribuidora $distribuidora): void
    {
        $rol === self::ADMIN
            ? $this->roles->asignarAdministrador($persona, $distribuidora)
            : $this->roles->asignarEmpleado($persona, $distribuidora);
    }

    private function exigirOtroAdmin(Usuario $persona, Distribuidora $distribuidora, ?DistribuidoraStaff $fila): void
    {
        if ($fila?->estado !== 'activo') {
            return;
        }

        $otro = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->administradoresActivos()
            ->where('usuario_id', '!=', $persona->id)
            ->lockForUpdate()
            ->exists();

        if (! $otro) {
            $this->rechazar('Es el único admin activo de '.$this->nombre($distribuidora).'. Primero nombra a otro admin.');
        }
    }

    /** Ni roles ni filas activas en otra: nadie queda en dos distribuidoras. */
    private function exigirSinOtraDistribuidora(Usuario $persona, int $distribuidoraId): void
    {
        $otraAsignacion = collect(PersonalPlataforma::rolesDe([(int) $persona->id])[$persona->id] ?? [])
            ->contains(fn (array $a) => $a['equipo'] > 0 && $a['equipo'] !== $distribuidoraId);

        $otraFila = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $persona->id)
            ->where('distribuidora_id', '!=', $distribuidoraId)
            ->where('estado', 'activo')
            ->exists();

        if ($otraAsignacion || $otraFila) {
            $this->rechazar('Ya tiene una asignación en otra distribuidora.');
        }
    }

    private function esCliente(Usuario $persona): bool
    {
        return Revendedor::withoutGlobalScopes()->where('usuario_id', $persona->id)->exists()
            || ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $persona->id)->exists();
    }

    private function resumen(string $rolAntes, ?Distribuidora $antes, string $rolDespues, ?Distribuidora $despues): array
    {
        return [
            'antes' => ['rol' => $rolAntes, 'distribuidora_id' => $antes?->id, 'distribuidora' => $antes ? $this->nombre($antes) : null],
            'despues' => ['rol' => $rolDespues, 'distribuidora_id' => $despues?->id, 'distribuidora' => $despues ? $this->nombre($despues) : null],
        ];
    }

    private function describir(array $asignacion): string
    {
        $etiqueta = PersonalPlataforma::ROLES[$asignacion['rol']];

        return $asignacion['distribuidora'] ? $etiqueta.' en '.$asignacion['distribuidora'] : $etiqueta;
    }

    private function nombre(Distribuidora $distribuidora): string
    {
        return (string) ($distribuidora->nombre_comercial ?: $distribuidora->razon_social);
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['rol_nuevo' => [$mensaje]]);
    }
}
