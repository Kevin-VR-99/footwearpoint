<?php

namespace App\Services\Auditoria;

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Support\Tenant;

/**
 * TG-333 (K35) — Inicios y cierres de sesión en la auditoría.
 *
 * Es por persona y sirve para seguridad; no tiene nada que ver con el
 * registro de actividad de A43 (actividad_diaria), que es anónimo y cuenta uso.
 *
 * Qué se guarda (lo que ya declara el aviso de privacidad 1.3):
 *  - quién, qué y cuándo (usuario, acción y fecha);
 *  - en el panel, la IP: el aviso cubre «la bitácora de acciones del panel
 *    (quién hizo qué, cuándo y desde qué IP)»;
 *  - en la app, SIN IP: el aviso no la declara para la app;
 *  - nunca el navegador.
 *
 * Se guarda con la distribuidora de la persona, para que cada una vea solo a
 * su personal y a sus clientes en /auditoria. Las del admin general quedan sin
 * distribuidora, y el admin general ve todas en /admin/auditoria.
 */
class RegistrarSesionAction
{
    public const INICIO_PANEL = 'sesion.inicio_panel';

    public const INICIO_RECORDADO = 'sesion.inicio_recordado';

    public const CIERRE_PANEL = 'sesion.cierre_panel';

    public const INICIO_APP = 'sesion.inicio_app';

    public const CIERRE_APP = 'sesion.cierre_app';

    private const DEL_PANEL = [self::INICIO_PANEL, self::INICIO_RECORDADO, self::CIERRE_PANEL];

    public function ejecutar(Usuario $usuario, string $accion): Auditoria
    {
        $panel = in_array($accion, self::DEL_PANEL, true);

        return Auditoria::create([
            'usuario_id' => $usuario->id,
            'distribuidora_id' => $this->distribuidoraDe($usuario),
            'accion' => $accion,
            'entidad_tipo' => 'usuario',
            'entidad_id' => $usuario->id,
            'datos_previos' => null,
            'datos_nuevos' => ['canal' => $panel ? 'panel' : 'app'],
            'ip_origen' => $panel ? request()?->ip() : null,
        ]);
    }

    /**
     * La distribuidora de la persona, aunque esté suspendida: si a alguien
     * lo sacan porque su distribuidora se suspendió, ese cierre también es
     * de ella. Null para el admin general.
     */
    private function distribuidoraDe(Usuario $usuario): ?int
    {
        return Tenant::paraUsuario((int) $usuario->id)
            ?? Tenant::distribuidoraSinOperacionDe((int) $usuario->id)?->id;
    }
}
