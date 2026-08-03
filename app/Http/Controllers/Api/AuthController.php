<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        if ($usuario->estado !== 'activo') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta no está activa.'],
            ]);
        }

        // Token Sanctum
        $token = $usuario->createToken('auth_token')->plainTextToken;

        // Obtener distribuidora_id si aplica
        $distribuidoraId = null;
        $staff = $usuario->membresiasStaff()->first();
        if ($staff) {
            $distribuidoraId = $staff->distribuidora_id;
        }

        // Obtener rol (compatible con Spatie teams)
        $rol = null;

        if ($distribuidoraId) {
            // Rol dentro de la distribuidora (team)
            setPermissionsTeamId($distribuidoraId);
            $rol = $usuario->getRoleNames()->first();
        }

        // Si no tiene rol de distribuidora, buscar rol global (admin_general)
        if (!$rol) {
            setPermissionsTeamId(0);
            $rol = $usuario->getRoleNames()->first();
        }

        return response()->json([
            'data' => [
                'token'            => $token,
                'token_type'       => 'Bearer',
                'usuario'          => [
                    'id'       => $usuario->id,
                    'nombre'   => $usuario->nombre,
                    'email'    => $usuario->email,
                    'telefono' => $usuario->telefono,
                    'estado'   => $usuario->estado,
                ],
                'rol'              => $rol,
                'distribuidora_id' => $distribuidoraId,
            ],
            'message' => 'Inicio de sesión exitoso.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
