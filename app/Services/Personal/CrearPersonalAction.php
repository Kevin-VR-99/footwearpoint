<?php

namespace App\Services\Personal;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Rules\CorreoConDominioReal;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Services\Auth\GenerarPasswordTemporalAction;
use App\Services\Correo\IntentarEnviarCorreoAction;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Services\Perfil\FotoPerfil;
use App\Support\PersonalPlataforma;
use App\Support\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * TG-336 (K38) — El admin general da de alta a una persona del personal.
 *
 * El correo debe estar libre en todo el sistema: nunca se liga a una cuenta
 * que ya existe. La contraseña temporal solo se regresa a quien llama, para
 * mostrarla una vez; en la base y en la auditoría no queda en claro.
 */
class CrearPersonalAction
{
    public const ACCESO_TEMPORAL = 'temporal';

    public const ACCESO_ENLACE = 'enlace';

    public const MENSAJE_CORREO_OCUPADO = 'Ese correo ya tiene una cuenta. Usa otro.';

    public function __construct(
        private ProvisionarRolesDistribuidoraAction $roles,
        private GenerarPasswordTemporalAction $passwords,
        private EnviarEnlaceRestablecerPanelAction $enlace,
        private RegistrarAuditoriaAction $auditoria,
        private FotoPerfil $fotos,
    ) {
    }

    /**
     * @param  array{nombre?: mixed, email?: mixed, telefono?: mixed, direccion?: mixed, rol?: mixed, distribuidora_id?: mixed, acceso?: mixed, confirmar_admin_general?: mixed}  $datos
     * @return array{usuario: Usuario, password_temporal: ?string, aviso_enlace: ?string, error_enlace: ?string}
     *
     * @throws ValidationException con los errores por campo.
     */
    public function ejecutar(array $datos, ?UploadedFile $foto = null): array
    {
        $datos = $this->validar($datos, $foto);

        $temporal = $datos['acceso'] === self::ACCESO_TEMPORAL ? $this->passwords->generar() : null;
        $fotoUrl = $foto ? $this->fotos->guardar($foto, 'personal') : null;

        try {
            $usuario = DB::transaction(fn () => $this->crear($datos, $temporal, $fotoUrl));
        } catch (UniqueConstraintViolationException) {
            $this->fotos->borrar($fotoUrl);

            throw ValidationException::withMessages(['email' => [self::MENSAJE_CORREO_OCUPADO]]);
        } catch (Throwable $e) {
            $this->fotos->borrar($fotoUrl);

            throw $e;
        }

        [$avisoEnlace, $errorEnlace] = $temporal === null ? $this->mandarEnlace($usuario) : [null, null];

        return [
            'usuario' => $usuario,
            'password_temporal' => $temporal,
            'aviso_enlace' => $avisoEnlace,
            'error_enlace' => $errorEnlace,
        ];
    }

    private function crear(array $datos, ?string $temporal, ?string $fotoUrl): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => $datos['nombre'],
            'email' => $datos['email'],
            // Con enlace nadie conoce esta clave: la define la persona.
            'password' => Hash::make($temporal ?? Str::random(64)),
            'telefono' => $datos['telefono'],
            'direccion' => $datos['direccion'],
            'foto_url' => $fotoUrl,
            'estado' => 'activo',
            'debe_cambiar_password' => $temporal !== null,
        ]);

        $this->darRol($usuario, $datos['rol'], $datos['distribuidora_id']);

        $this->auditoria->ejecutar('personal.creado', 'usuario', (int) $usuario->id, null, array_filter([
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
            'telefono' => $usuario->telefono,
            'direccion' => $usuario->direccion,
            'foto_url' => $usuario->foto_url,
            'rol' => $datos['rol'],
            'distribuidora_id' => $datos['distribuidora_id'],
            'acceso' => $datos['acceso'],
        ], fn ($valor) => $valor !== null));

        if ($temporal !== null) {
            $this->auditoria->ejecutar('password.temporal_generada', 'usuario', (int) $usuario->id, null, [
                'email' => $usuario->email,
            ]);
        }

        DB::afterCommit(fn () => app(IntentarEnviarCorreoAction::class)->ejecutar(
            fn () => $usuario->sendEmailVerificationNotification(),
            'confirmacion',
            ['usuario_id' => $usuario->id, 'email' => $usuario->email],
        ));

        return $usuario;
    }

    private function darRol(Usuario $usuario, string $rol, ?int $distribuidoraId): void
    {
        if ($rol === PersonalPlataforma::ADMIN_GENERAL) {
            $this->roles->asignarAdminGeneral($usuario);

            return;
        }

        $distribuidora = Distribuidora::query()->findOrFail($distribuidoraId);
        $esAdministrador = $rol === ProvisionarRolesDistribuidoraAction::ROL_ADMINISTRADOR;

        Tenant::forzar((int) $distribuidora->id, fn () => DistribuidoraStaff::create([
            'distribuidora_id' => $distribuidora->id,
            'usuario_id' => $usuario->id,
            // El enum de distribuidora_staff.tipo es administrador|empleado.
            'tipo' => $esAdministrador ? 'administrador' : 'empleado',
            'estado' => 'activo',
            'fecha_alta' => now(),
        ]));

        $this->roles->ejecutar($distribuidora);

        $esAdministrador
            ? $this->roles->asignarAdministrador($usuario, $distribuidora)
            : $this->roles->asignarEmpleado($usuario, $distribuidora);
    }

    /**
     * La cuenta ya quedó creada: si el correo falla no se deshace el alta, se
     * avisa. La persona puede pedir otro enlace desde «Olvidé mi contraseña».
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function mandarEnlace(Usuario $usuario): array
    {
        try {
            return [$this->enlace->ejecutar($usuario, 'usuario', (int) $usuario->id), null];
        } catch (ValidationException $e) {
            return [null, $e->errors()['enlace'][0] ?? 'No se pudo enviar el enlace.'];
        }
    }

    private function validar(array $datos, ?UploadedFile $foto): array
    {
        $limpios = [
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'email' => trim((string) ($datos['email'] ?? '')),
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'rol' => (string) ($datos['rol'] ?? ''),
            'distribuidora_id' => ($datos['distribuidora_id'] ?? '') === '' ? null : $datos['distribuidora_id'],
            'acceso' => (string) ($datos['acceso'] ?? ''),
            'confirmar_admin_general' => $datos['confirmar_admin_general'] ?? false,
            'foto' => $foto,
        ];

        $esAdminGeneral = $limpios['rol'] === PersonalPlataforma::ADMIN_GENERAL;

        // El admin general no tiene distribuidora y el aviso de privacidad no
        // declara su dirección: aunque llegue, no se guarda.
        if ($esAdminGeneral) {
            $limpios['direccion'] = null;
            $limpios['distribuidora_id'] = null;
        }

        $validos = Validator::make($limpios, [
            'nombre' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', new CorreoConDominioReal(), 'max:190', Rule::unique('usuarios', 'email')],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'rol' => ['required', Rule::in(array_keys(PersonalPlataforma::ROLES))],
            'distribuidora_id' => [
                Rule::requiredIf(! $esAdminGeneral),
                'nullable',
                'integer',
                Rule::exists('distribuidoras', 'id')->where('estado', 'activa'),
            ],
            'acceso' => ['required', Rule::in([self::ACCESO_TEMPORAL, self::ACCESO_ENLACE])],
            'confirmar_admin_general' => ['accepted_if:rol,'.PersonalPlataforma::ADMIN_GENERAL],
            'foto' => ['nullable', ...FotoPerfil::reglas()],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede pasar de 150 caracteres.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'email.max' => 'El correo no puede pasar de 190 caracteres.',
            'email.unique' => self::MENSAJE_CORREO_OCUPADO,
            'telefono.max' => 'El teléfono no puede pasar de 30 caracteres.',
            'direccion.max' => 'La dirección no puede pasar de 300 caracteres.',
            'rol.required' => 'Elige un rol.',
            'rol.in' => 'Elige un rol de personal.',
            'distribuidora_id.required' => 'Elige la distribuidora.',
            'distribuidora_id.integer' => 'Elige una distribuidora activa.',
            'distribuidora_id.exists' => 'Elige una distribuidora activa.',
            'acceso.required' => 'Elige cómo va a entrar.',
            'acceso.in' => 'Elige cómo va a entrar.',
            'confirmar_admin_general.accepted_if' => 'Confirma que esta persona tendrá acceso total.',
            ...FotoPerfil::mensajes('foto'),
        ])->validate();

        $validos['distribuidora_id'] = $validos['distribuidora_id'] === null ? null : (int) $validos['distribuidora_id'];

        return $validos;
    }
}
