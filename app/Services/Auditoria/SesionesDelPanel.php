<?php

namespace App\Services\Auditoria;

use App\Models\Usuario;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

/**
 * TG-333 (K35) — Escucha los eventos de sesión del panel (guard «web»).
 *
 * - Login: con contraseña, o «recordado» cuando la sesión se perdió y la
 *   cookie de «recordarme» volvió a entrar sola.
 * - Logout y CurrentDeviceLogout: «Cerrar sesión» usa logoutCurrentDevice();
 *   los cierres forzados (distribuidora suspendida, personal dado de baja)
 *   usan logout().
 *
 * La app usa tokens (Sanctum), que no disparan estos eventos: su inicio y
 * cierre se registran en Api\AuthController. Un token que vence solo no es un
 * cierre de sesión.
 *
 * Vive fuera de app/Listeners a propósito, para que Laravel no lo registre
 * dos veces (una por descubrimiento automático y otra aquí).
 */
class SesionesDelPanel
{
    public static function escuchar(): void
    {
        Event::listen(Login::class, [self::class, 'alEntrar']);
        Event::listen(Logout::class, [self::class, 'alSalir']);
        Event::listen(CurrentDeviceLogout::class, [self::class, 'alSalir']);
    }

    public function __construct(private RegistrarSesionAction $registrar)
    {
    }

    public function alEntrar(Login $evento): void
    {
        if ($evento->guard !== 'web' || ! $evento->user instanceof Usuario) {
            return;
        }

        $recordado = Auth::guard('web')->viaRemember();

        $this->registrar->ejecutar(
            $evento->user,
            $recordado ? RegistrarSesionAction::INICIO_RECORDADO : RegistrarSesionAction::INICIO_PANEL
        );
    }

    public function alSalir(Logout|CurrentDeviceLogout $evento): void
    {
        if ($evento->guard !== 'web' || ! $evento->user instanceof Usuario) {
            return;
        }

        $this->registrar->ejecutar($evento->user, RegistrarSesionAction::CIERRE_PANEL);
    }
}
