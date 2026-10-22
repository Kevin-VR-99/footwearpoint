<?php

namespace App\Services\Distribuidora;

use App\Models\DispositivoFcm;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corta las sesiones de la app de una persona: borra sus tokens de Sanctum
 * y sus celulares registrados para push. La sesión web del personal la corta
 * SoloPersonalPanel en la siguiente petición; la base de sesiones, si se usa,
 * se limpia aquí también.
 */
class RevocarAccesoUsuarioAction
{
    public function ejecutar(?int $usuarioId): int
    {
        if ($usuarioId === null) {
            return 0;
        }

        $usuario = Usuario::query()->find($usuarioId);
        if ($usuario === null) {
            return 0;
        }

        $tokens = $usuario->tokens()->delete();
        DispositivoFcm::query()->where('usuario_id', $usuarioId)->delete();

        if (config('session.driver') === 'database') {
            try {
                DB::table(config('session.table', 'sessions'))->where('user_id', $usuarioId)->delete();
            } catch (\Throwable $e) {
                Log::info('No se pudieron borrar las sesiones web al dar de baja.', ['usuario_id' => $usuarioId, 'error' => $e->getMessage()]);
            }
        }

        return (int) $tokens;
    }
}
