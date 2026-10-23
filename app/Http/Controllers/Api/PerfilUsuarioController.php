<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Perfil\ActualizarPerfilRequest;
use App\Http\Requests\Perfil\CambiarPasswordRequest;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Rules\CorreoConDominioReal;
use App\Services\Perfil\CambioContactoAction;
use App\Services\Perfil\FotoPerfil;
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
            array_key_exists('telefono', $request->validated()),
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

    /** POST /api/perfil/foto (multipart, campo «foto»). */
    public function foto(Request $request, FotoPerfil $fotos): JsonResponse
    {
        $request->validate(['foto' => ['required', ...FotoPerfil::reglas()]], [
            'foto.required' => 'Elige una foto.',
            ...FotoPerfil::mensajes('foto'),
        ]);

        $usuario = $request->user();
        $contacto = $usuario->contacto();
        $titular = $contacto instanceof Revendedor ? $contacto : $usuario;

        $titular->forceFill([
            'foto_url' => $fotos->guardar($request->file('foto'), $contacto instanceof Revendedor ? 'revendedores' : 'usuarios', $titular->foto_url),
        ])->save();

        $usuario->olvidarContacto();

        return response()->json([
            'data'    => $this->datosUsuario($usuario->refresh()),
            'message' => 'Foto actualizada.',
        ]);
    }

    /** POST /api/perfil/correo: manda el enlace al correo NUEVO. */
    public function cambiarCorreo(Request $request, CambioContactoAction $accion): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', new CorreoConDominioReal(), 'max:190'],
        ], [
            'email.required' => 'Escribe el correo nuevo.',
            'email.email'    => 'Escribe un correo válido.',
        ]);

        $aviso = $accion->solicitarCorreo($request->user(), $datos['email']);

        return response()->json([
            'data'    => $this->datosUsuario($request->user()->refresh()),
            'message' => $aviso,
        ]);
    }

    /** POST /api/perfil/telefono: manda el enlace al correo actual. */
    public function cambiarTelefono(Request $request, CambioContactoAction $accion): JsonResponse
    {
        if ($request->has('telefono')) {
            $request->merge(['telefono' => trim((string) $request->input('telefono'))]);
        }

        $datos = $request->validate([
            'telefono' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\s-]{7,30}$/'],
        ], [
            'telefono.required' => 'Escribe el teléfono nuevo.',
            'telefono.regex'    => 'Escribe solo números (puede llevar +, espacios o guiones).',
            'telefono.max'      => 'El teléfono no puede tener más de 30 caracteres.',
        ]);

        $aviso = $accion->solicitarTelefono($request->user(), $datos['telefono']);

        return response()->json([
            'data'    => $this->datosUsuario($request->user()->refresh()),
            'message' => $aviso,
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
            'nombre'   => $usuario->nombreVisible(),
            'email'    => $usuario->email,
            'telefono' => $usuario->telefonoVisible(),
            'estado'   => $usuario->estado,
            'foto_url' => $usuario->fotoVisible(),
            'cambios_pendientes' => (object) app(CambioContactoAction::class)->pendientesDe($usuario),
        ];
    }
}
