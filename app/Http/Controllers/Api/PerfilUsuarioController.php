<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Perfil\ActualizarPerfilRequest;
use App\Http\Requests\Perfil\CambiarPasswordRequest;
use App\Models\Usuario;
use App\Services\Perfil\ActualizarPerfilAction;
use App\Services\Perfil\CambiarPasswordAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Perfil del usuario que inició sesión (E1-05 / TG-110).
 *
 * No confundir con Api\Distribuidora\PerfilController, que es el perfil de la
 * distribuidora. Aquí no hay {id} en ninguna ruta: cada quien solo puede ver
 * y editar su propia cuenta, la del token.
 */
class PerfilUsuarioController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->datosUsuario($request->user()),
        ]);
    }

    public function update(ActualizarPerfilRequest $request, ActualizarPerfilAction $accion): JsonResponse
    {
        $usuario = $accion->ejecutar(
            $request->user(),
            $request->validated('nombre'),
            $request->validated('telefono'),
        );

        return response()->json([
            'data'    => $this->datosUsuario($usuario),
            'message' => 'Datos actualizados correctamente.',
        ]);
    }

    public function cambiarPassword(CambiarPasswordRequest $request, CambiarPasswordAction $accion): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $accion->ejecutar(
            $request->user(),
            $request->validated('password_actual'),
            $request->validated('password'),
            $token instanceof PersonalAccessToken ? $token : null,
        );

        return response()->json([
            'message' => 'Contraseña actualizada. Se cerró la sesión en tus otros dispositivos.',
        ]);
    }

    /**
     * Misma forma que el objeto "usuario" del login y de auth/me, para que la
     * app lo lea con el mismo modelo.
     */
    private function datosUsuario(Usuario $usuario): array
    {
        return [
            'id'       => $usuario->id,
            'nombre'   => $usuario->nombre,
            'email'    => $usuario->email,
            'telefono' => $usuario->telefono,
            'estado'   => $usuario->estado,
        ];
    }
}
