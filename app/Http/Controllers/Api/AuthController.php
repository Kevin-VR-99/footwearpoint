<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterEmpleadoRequest;
use App\Models\DistribuidoraStaff;
use Illuminate\Support\Facades\DB;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Auth\AceptarLegalesRequest;
use App\Models\AceptacionLegal;

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

    public function registerEmpleado(RegisterEmpleadoRequest $request)
    {
        try {
            $admin = $request->user();

            // Sin Global Scope para evitar el crash
            $staffAdmin = \App\Models\DistribuidoraStaff::withoutGlobalScopes()
                ->where('usuario_id', $admin->id)
                ->first();

            if (!$staffAdmin) {
                return response()->json([
                    'message' => 'No se encontró distribuidora asociada.',
                ], 403);
            }

            $distribuidoraId = $staffAdmin->distribuidora_id;

            $usuario = Usuario::create([
                'nombre'   => $request->nombre,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
                'estado'   => 'activo',
            ]);

            \App\Models\DistribuidoraStaff::withoutGlobalScopes()->create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id'       => $usuario->id,
                'tipo'             => 'empleado',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]);

            setPermissionsTeamId($distribuidoraId);
            $usuario->assignRole('empleado');

            return response()->json([
                'data' => [
                    'id'               => $usuario->id,
                    'nombre'           => $usuario->nombre,
                    'email'            => $usuario->email,
                    'telefono'         => $usuario->telefono,
                    'estado'           => $usuario->estado,
                    'rol'              => 'empleado',
                    'distribuidora_id' => $distribuidoraId,
                ],
                'message' => 'Empleado registrado correctamente.',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al registrar empleado.',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    public function aceptarLegales(AceptarLegalesRequest $request)
    {
        $usuario = $request->user();
        $ip = $request->ip();

        $aceptadas = [];

        foreach ($request->documentos as $doc) {
            $aceptacion = AceptacionLegal::firstOrCreate(
                [
                    'usuario_id'     => $usuario->id,
                    'tipo_documento' => $doc['tipo_documento'],
                    'version'        => $doc['version'],
                ],
                [
                    'fecha_aceptacion' => now(),
                    'ip_origen'        => $ip,
                ]
            );

            $aceptadas[] = [
                'tipo_documento'   => $aceptacion->tipo_documento,
                'version'          => $aceptacion->version,
                'fecha_aceptacion' => $aceptacion->fecha_aceptacion,
            ];
        }

        return response()->json([
            'data'    => $aceptadas,
            'message' => 'Documentos legales aceptados correctamente.',
        ], 201);
    }
}
