<?php

use App\Rules\CorreoConDominioReal;
use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Services\Correo\IntentarEnviarCorreoAction;
use App\Services\Auth\GenerarPasswordTemporalAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Support\Tenant;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Services\Distribuidora\CodigoInternoRevendedor;
use App\Services\Distribuidora\EliminarPersonaAction;
use App\Services\Perfil\FotoPerfil;
use App\Support\MensajeError;
use Spatie\Permission\PermissionRegistrar;

new #[Layout('layouts.panel')] #[Title('Usuarios — FootwearPoint')] class extends Component {
    use \App\Livewire\Concerns\GestionaBajasDeAcceso;
    use WithFileUploads;

    private function despuesDeCambioDeAcceso(): void
    {
        $this->cargarEmpleados();
        $this->cargarRevendedores();
    }
    /** empleados o mayoristas. Lo decide la ruta. */
    public string $seccion = 'empleados';
    public $empleados = [];
    public array $permisosEmpleado = [];
    public bool $mostrandoFormularioEmpleado = false;
    public string $empleado_nombre = '';
    public string $empleado_email = '';
    public ?string $empleado_telefono = null;
    public string $empleado_password = '';
    public string $empleado_password_confirmation = '';
    #[Locked]
    public ?int $empleadoEditandoId = null;
    #[Locked]
    public ?string $empleado_email_actual = null;
    #[Locked]
    public ?string $empleado_foto_actual = null;
    /** Foto nueva (temporal de Livewire) del empleado. */
    public $empleado_foto = null;

    /** Eliminar definitivamente: ['tipo' => 'empleado'|'revendedor', 'id' => int, 'nombre' => string]. */
    #[Locked]
    public ?array $porEliminar = null;
    public ?string $errorEliminar = null;

    public ?string $avisoEnlace = null;
    public bool $avisoEnlaceEsError = false;

    public $revendedores = [];
    #[Locked]
    public ?int $revendedorEditandoId = null;
    public bool $mostrandoFormularioRevendedor = false;
    public string $revendedor_nombre = '';
    public ?string $revendedor_telefono = null;
    public ?string $revendedor_email = null;
    public ?string $revendedor_codigo_interno = null;
    public string $revendedor_estado = 'activo';

    public ?string $revendedor_cuenta_email_actual = null;
    public ?string $revendedor_acceso_email = null;
    /** Si ya escribieron a mano el correo de acceso, el de contacto deja de copiarse. */
    public bool $accesoEditadoAMano = false;
    #[Locked]
    public ?string $revendedor_foto_actual = null;
    public $revendedor_foto = null;

    #[\Livewire\Attributes\Url(except: '')]
    public string $buscar = '';
    public string $filtroEstado = '';

    public function mount(): void
    {
        if (request()->routeIs('distribuidora.mayoristas')) {
            $this->seccion = 'mayoristas';
        }

        $this->cargarEmpleados();
        $this->cargarRevendedores();
    }

    private function cargarEmpleados(): void
    {
        $this->empleados = DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->get();
        $this->permisosEmpleado = [];

        $this->enEquipo(function () {
            foreach ($this->empleados as $empleado) {
                $usuario = $empleado->usuario;
                if (! $usuario) {
                    $this->permisosEmpleado[$empleado->id] = [];
                    continue;
                }
                $usuario->unsetRelation('permissions');
                $finos = array_keys(array_filter(\App\Services\Auth\PermisoModuloService::finosDe($empleado)));
                $this->permisosEmpleado[$empleado->id] = array_values(array_merge(
                    $usuario->getDirectPermissions()->pluck('name')->all(),
                    $finos,
                ));
            }
        });
    }

    private function consultaRevendedores()
    {
        $q = RevendedorDistribuidora::with('revendedor.usuario')->orderBy('id');
        if ($this->filtroEstado !== '') {
            $q->where('estado', $this->filtroEstado);
        }
        if (trim($this->buscar) !== '') {
            $t = '%'.addcslashes(trim($this->buscar), '%_\\').'%';
            $q->where(fn ($w) => $w->where('codigo_interno', 'like', $t)
                ->orWhereHas('revendedor', fn ($r) => $r->where('nombre', 'like', $t)
                    ->orWhere('telefono', 'like', $t)
                    ->orWhere('email', 'like', $t)
                    ->orWhereHas('usuario', fn ($u) => $u->where('email', 'like', $t))));
        }

        return $q;
    }

    private function cargarRevendedores(): void
    {
        $this->revendedores = $this->consultaRevendedores()->get();
    }

    public function updatedBuscar(): void
    {
        $this->cargarRevendedores();
    }

    public function updatedFiltroEstado(): void
    {
        $this->cargarRevendedores();
    }

    /** Exporta a .xlsx los clientes mayoristas con la búsqueda y el estado de la pantalla. */
    public function exportar()
    {
        \App\Services\Exportar\ExportarListadoXlsx::exigirPersonal(soloAdministrador: true);

        $filas = $this->consultaRevendedores()->with('revendedor.usuario')
            ->limit(\App\Services\Exportar\ExportarListadoXlsx::MAX_FILAS + 1)->get()->map(fn (RevendedorDistribuidora $a) => [
            $a->revendedor?->nombre, $a->codigo_interno, $a->revendedor?->telefono,
            $a->revendedor?->emailVisible(),
            ucfirst((string) $a->estado), $a->revendedor?->usuario ? 'Sí' : 'No', $a->fecha_alta?->format('d/m/Y'),
        ]);

        return app(\App\Services\Exportar\ExportarListadoXlsx::class)->descargar(
            'clientes-mayoristas-'.now()->format('Ymd-His').'.xlsx', 'Clientes mayoristas',
            ['Nombre', 'Código', 'Teléfono', 'Correo', 'Estado', 'Cuenta app', 'Alta'],
            $filas,
            array_filter(['Búsqueda' => trim($this->buscar) ?: null, 'Estado' => $this->filtroEstado !== '' ? ucfirst($this->filtroEstado) : null]),
        );
    }

    public function toggleEstadoEmpleado(int $id): void
    {
        $empleado = DistribuidoraStaff::findOrFail($id);
        // Baja/reactivación con revocación de tokens y bitácora (BajaAccesoAction).
        $empleado->estado === 'activo' ? $this->darDeBaja('empleado', $id) : $this->reactivar('empleado', $id);
        $empleado->refresh();
        $this->cargarEmpleados();
    }

    public function guardarPermisos(int $id): void
    {
        $empleado = DistribuidoraStaff::with('usuario')->findOrFail($id);
        $usuario = $empleado->usuario;
        abort_if(! $usuario, 404);

        $marcados = $this->permisosEmpleado[$id] ?? [];
        $validos = array_values(array_intersect($marcados, RolesPermissionsSeeder::PERMISOS_POR_MODULO));

        $antes = \App\Services\Auth\PermisoModuloService::finosDe($empleado);
        $finos = [];
        foreach (array_keys(\App\Services\Auth\PermisoModuloService::PERMISOS_FINOS) as $permiso) {
            $finos[$permiso] = in_array($permiso, $marcados, true);
        }

        $this->enEquipo(function () use ($usuario, $validos) {
            $usuario->syncPermissions($validos);
        });

        $empleado->forceFill(['permisos_finos' => $finos])->save();

        app(\App\Services\Auditoria\RegistrarAuditoriaAction::class)->ejecutar(
            'empleado.permisos_actualizados', 'distribuidora_staff', (int) $empleado->id,
            ['finos' => $antes], ['modulo' => $validos, 'finos' => $finos],
        );

        $this->cargarEmpleados();
        $this->dispatch('guardado', mensaje: 'Permisos del empleado actualizados.');
    }

    private function enEquipo(callable $callback): void
    {
        $registrar = app(PermissionRegistrar::class);
        $anterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId(Tenant::id());
            $callback();
        } finally {
            $registrar->setPermissionsTeamId($anterior);
            $registrar->forgetCachedPermissions();
        }
    }

    public function abrirFormularioInvitarEmpleado(): void
    {
        $this->empleadoEditandoId = null;
        $this->empleado_email_actual = null;
        $this->empleado_foto_actual = null;
        $this->empleado_foto = null;
        $this->empleado_nombre = '';
        $this->empleado_email = '';
        $this->empleado_telefono = null;
        $this->empleado_password = '';
        $this->empleado_password_confirmation = '';
        $this->resetErrorBag();
        $this->mostrandoFormularioEmpleado = true;
    }

    public function abrirFormularioEditarEmpleado(int $id): void
    {
        $staff = DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->findOrFail($id);
        abort_if(! $staff->usuario, 404);

        $this->empleadoEditandoId = $staff->id;
        $this->empleado_email_actual = $staff->usuario->email;
        $this->empleado_foto_actual = $staff->usuario->foto_url;
        $this->empleado_foto = null;
        $this->empleado_nombre = (string) $staff->usuario->nombre;
        $this->empleado_email = (string) $staff->usuario->email;
        $this->empleado_telefono = $staff->usuario->telefono;
        $this->empleado_password = '';
        $this->empleado_password_confirmation = '';
        $this->resetErrorBag();
        $this->mostrandoFormularioEmpleado = true;
    }

    public function cancelarFormularioEmpleado(): void
    {
        $this->mostrandoFormularioEmpleado = false;
        $this->empleadoEditandoId = null;
        $this->empleado_foto = null;
        $this->resetErrorBag();
    }

    public function quitarFotoNuevaEmpleado(): void
    {
        $this->empleado_foto = null;
        $this->resetErrorBag('empleado_foto');
    }

    public function guardarEmpleado(): void
    {
        if ($this->empleadoEditandoId) {
            $this->actualizarEmpleado();

            return;
        }

        $this->validate([
            'empleado_nombre' => ['required', 'string', 'max:150'],
            'empleado_email' => ['required', 'email', new CorreoConDominioReal(), 'max:190', 'unique:usuarios,email'],
            'empleado_telefono' => ['nullable', 'string', 'max:30'],
            'empleado_password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'max:255', 'same:empleado_password_confirmation'],
            'empleado_foto' => ['nullable', ...FotoPerfil::reglas()],
        ], [
            'empleado_password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'empleado_nombre.required' => 'El nombre es obligatorio.',
            'empleado_email.required' => 'El correo es obligatorio.',
            'empleado_email.unique' => 'Ese correo ya está registrado.',
            'empleado_password.required' => 'La contraseña es obligatoria.',
            'empleado_password.min' => 'Mínimo 8 caracteres.',
            'empleado_password.same' => 'Las contraseñas no coinciden.',
            ...FotoPerfil::mensajes('empleado_foto'),
        ]);

        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403);

        $foto = $this->empleado_foto ? app(FotoPerfil::class)->guardar($this->empleado_foto, 'empleados') : null;

        DB::transaction(function () use ($distribuidoraId, $foto) {
            $usuario = Usuario::create([
                'nombre' => $this->empleado_nombre,
                'email' => $this->empleado_email,
                'password' => Hash::make($this->empleado_password),
                'telefono' => $this->empleado_telefono ?: null,
                'foto_url' => $foto,
                'estado' => 'activo',
            ]);

            Tenant::forzar($distribuidoraId, fn () => DistribuidoraStaff::create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id' => $usuario->id,
                'tipo' => 'empleado',
                'estado' => 'activo',
                'fecha_alta' => now(),
            ]));

            setPermissionsTeamId($distribuidoraId);
            $usuario->assignRole('empleado');
        });

        $this->mostrandoFormularioEmpleado = false;
        $this->empleado_foto = null;
        $this->cargarEmpleados();
        $this->dispatch('guardado', mensaje: 'Empleado registrado correctamente.');
    }

    /** Editar: nombre, teléfono y foto. El correo es su acceso y no se cambia aquí. */
    private function actualizarEmpleado(): void
    {
        $this->validate([
            'empleado_nombre' => ['required', 'string', 'max:150'],
            'empleado_telefono' => ['nullable', 'string', 'max:30'],
            'empleado_foto' => ['nullable', ...FotoPerfil::reglas()],
        ], [
            'empleado_nombre.required' => 'El nombre es obligatorio.',
            ...FotoPerfil::mensajes('empleado_foto'),
        ]);

        $staff = DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->findOrFail($this->empleadoEditandoId);
        $usuario = $staff->usuario;
        abort_if(! $usuario, 404);

        $antes = ['nombre' => $usuario->nombre, 'telefono' => $usuario->telefono, 'foto_url' => $usuario->foto_url];

        $datos = [
            'nombre' => trim($this->empleado_nombre),
            'telefono' => trim((string) $this->empleado_telefono) ?: null,
        ];

        if ($this->empleado_foto) {
            $datos['foto_url'] = app(FotoPerfil::class)->guardar($this->empleado_foto, 'empleados', $usuario->foto_url);
        }

        $usuario->forceFill($datos)->save();

        app(\App\Services\Auditoria\RegistrarAuditoriaAction::class)->ejecutar(
            'empleado.actualizado', 'distribuidora_staff', (int) $staff->id, $antes, $datos,
        );

        $this->mostrandoFormularioEmpleado = false;
        $this->empleadoEditandoId = null;
        $this->empleado_foto = null;
        $this->cargarEmpleados();
        $this->dispatch('guardado', mensaje: 'Empleado actualizado.');
    }

    /** Abre el modal de «Eliminar definitivamente». */
    public function pedirEliminar(string $tipo, int $id): void
    {
        abort_unless(in_array($tipo, ['empleado', 'revendedor'], true), 404);

        $nombre = $tipo === 'empleado'
            ? DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->findOrFail($id)->usuario?->nombreVisible()
            : RevendedorDistribuidora::with('revendedor')->findOrFail($id)->revendedor?->nombre;

        $this->porEliminar = ['tipo' => $tipo, 'id' => $id, 'nombre' => (string) ($nombre ?: 'Sin nombre')];
        $this->errorEliminar = null;
    }

    public function cancelarEliminar(): void
    {
        $this->porEliminar = null;
        $this->errorEliminar = null;
    }

    public function eliminarDefinitivamente(): void
    {
        if ($this->porEliminar === null) {
            return;
        }

        ['tipo' => $tipo, 'id' => $id, 'nombre' => $nombre] = $this->porEliminar;

        try {
            $accion = app(EliminarPersonaAction::class);
            $tipo === 'empleado' ? $accion->empleado($id) : $accion->revendedor($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->errorEliminar = 'Ya no existe: alguien más lo eliminó.';

            return;
        } catch (\Throwable $e) {
            $this->errorEliminar = MensajeError::paraUsuario($e, 'No se pudo eliminar.');

            return;
        }

        $this->porEliminar = null;
        $this->errorEliminar = null;
        $this->cargarEmpleados();
        $this->cargarRevendedores();
        $this->dispatch('guardado', mensaje: $nombre.' quedó eliminado definitivamente.');
    }

    public function abrirFormularioAfiliarRevendedor(): void
    {
        $this->revendedorEditandoId = null;
        $this->revendedor_nombre = '';
        $this->revendedor_telefono = null;
        $this->revendedor_email = null;
        // Solo de muestra: el código real se toma al guardar.
        $this->revendedor_codigo_interno = app(CodigoInternoRevendedor::class)->siguiente((int) Tenant::id());
        $this->revendedor_estado = 'activo';
        $this->limpiarAccesoRevendedor();
        $this->resetErrorBag();
        $this->mostrandoFormularioRevendedor = true;
    }

    public function abrirFormularioEditarRevendedor(int $id): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor.usuario')->findOrFail($id);
        $this->revendedorEditandoId = $afiliacion->id;
        $this->revendedor_nombre = $afiliacion->revendedor->nombre;
        $this->revendedor_telefono = $afiliacion->revendedor->telefono;
        $this->revendedor_email = $afiliacion->revendedor->email;
        $this->revendedor_codigo_interno = $afiliacion->codigo_interno;
        $this->revendedor_estado = $afiliacion->estado;
        $this->limpiarAccesoRevendedor();
        $this->revendedor_cuenta_email_actual = $afiliacion->revendedor->usuario?->email;
        $this->revendedor_foto_actual = $afiliacion->revendedor->foto_url;
        $this->resetErrorBag();
        $this->mostrandoFormularioRevendedor = true;
    }

    public function enviarEnlaceRevendedor(int $id): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor.usuario')->findOrFail($id);
        $cuenta = $afiliacion->revendedor->usuario;

        if (! $cuenta) {
            $this->avisoEnlace = 'Ese cliente mayorista todavia no tiene cuenta para la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        try {
            $this->avisoEnlace = app(EnviarEnlaceRestablecerPanelAction::class)
                ->ejecutar($cuenta, 'revendedor', (int) $afiliacion->revendedor_id);
            $this->avisoEnlaceEsError = false;
        } catch (ValidationException $e) {
            $this->avisoEnlace = $e->errors()['enlace'][0] ?? 'No se pudo enviar el enlace.';
            $this->avisoEnlaceEsError = true;
        }
    }

    public function generarPasswordTemporalRevendedor(int $id): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor.usuario')->findOrFail($id);
        $cuenta = $afiliacion->revendedor->usuario;
        $entidadId = $afiliacion->revendedor_id;

        if (! $cuenta) {
            $this->avisoEnlace = 'Primero hay que darle acceso a la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        try {
            $password = app(GenerarPasswordTemporalAction::class)
                ->ejecutar($cuenta, 'revendedor', (int) $entidadId);
        } catch (ValidationException $e) {
            $this->avisoEnlace = $e->errors()['password_temporal'][0]
                ?? 'No se pudo generar la contraseña temporal.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        session()->now('aviso_password_temporal', 'Contraseña temporal para '.$cuenta->email.': '.$password
            .'. Anótala ahora, no se vuelve a mostrar. Se le pedirá cambiarla al entrar.');

        $this->avisoEnlace = null;
        $this->avisoEnlaceEsError = false;
    }

    /**
     * TG-189 (A11): reenviar el correo de confirmacion desde el mostrador,
     * cuando la persona dice que no le llego.
     */
    public function reenviarVerificacionRevendedor(int $id): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor.usuario')->findOrFail($id);
        $cuenta = $afiliacion->revendedor->usuario;

        if (! $cuenta) {
            $this->avisoEnlace = 'Primero hay que darle acceso a la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        if ($cuenta->hasVerifiedEmail()) {
            $this->avisoEnlace = 'Ese correo ya esta confirmado.';
            $this->avisoEnlaceEsError = false;

            return;
        }

        $fallo = app(IntentarEnviarCorreoAction::class)->ejecutar(
            fn () => $cuenta->sendEmailVerificationNotification(),
            'confirmacion',
            ['usuario_id' => $cuenta->id, 'email' => $cuenta->email, 'tipo' => 'revendedor'],
        );

        if ($fallo !== null) {
            $this->avisoEnlace = $fallo;
            $this->avisoEnlaceEsError = true;

            return;
        }

        $this->avisoEnlace = 'Le reenviamos el enlace de confirmacion a '.$cuenta->email.'.';
        $this->avisoEnlaceEsError = false;
    }

    public function cancelarFormularioRevendedor(): void
    {
        $this->mostrandoFormularioRevendedor = false;
        $this->revendedorEditandoId = null;
        $this->limpiarAccesoRevendedor();
    }

    /**
     * El correo de contacto se copia al de acceso mientras nadie haya escrito
     * en este último (se puede cambiar después).
     */
    public function updatedRevendedorEmail($valor): void
    {
        if ($this->revendedor_cuenta_email_actual === null && ! $this->accesoEditadoAMano) {
            $this->revendedor_acceso_email = $valor ?: null;
        }
    }

    public function updatedRevendedorAccesoEmail($valor): void
    {
        $this->accesoEditadoAMano = filled($valor) && $valor !== $this->revendedor_email;
    }

    public function quitarFotoNuevaRevendedor(): void
    {
        $this->revendedor_foto = null;
        $this->resetErrorBag('revendedor_foto');
    }

    public function guardarRevendedor(): void
    {
        $datos = $this->validate([
            'revendedor_nombre' => ['required', 'string', 'max:150'],
            'revendedor_telefono' => ['nullable', 'string', 'max:30'],
            'revendedor_email' => ['nullable', 'email', new CorreoConDominioReal(), 'max:190'],
            'revendedor_acceso_email' => ['nullable', 'email', new CorreoConDominioReal(), 'max:190'],
            'revendedor_foto' => ['nullable', ...FotoPerfil::reglas()],
        ], [
            'revendedor_nombre.required' => 'El nombre es obligatorio.',
            'revendedor_acceso_email.email' => 'Escribe un correo válido para entrar a la app.',
            ...FotoPerfil::mensajes('revendedor_foto'),
        ]);

        $payload = [
            'nombre' => $datos['revendedor_nombre'],
            'telefono' => $datos['revendedor_telefono'],
            ...($this->revendedor_cuenta_email_actual === null
                ? ['email' => $datos['revendedor_email']]
                : []),
            'estado' => $this->revendedor_estado,
        ];

        $accion = app(GestionarRevendedorAction::class);
        $darAcceso = filled($datos['revendedor_acceso_email']) && $this->revendedor_cuenta_email_actual === null;
        // PR #252: la única forma de alta es con contraseña temporal generada
        // (cumple Password::defaults); se le pide cambiarla al entrar.
        $passwordTemporal = $darAcceso ? app(GenerarPasswordTemporalAction::class)->generar() : null;
        $fotoNueva = null;

        try {
            $afiliacion = DB::transaction(function () use ($accion, $payload, $datos, $darAcceso, $passwordTemporal) {
                $afiliacion = $this->revendedorEditandoId
                    ? $accion->actualizar(RevendedorDistribuidora::findOrFail($this->revendedorEditandoId), $payload)
                    : $accion->afiliar($payload);

                if ($darAcceso) {
                    $cuenta = app(ActivarCuentaAccesoAction::class)->paraRevendedor(
                        $afiliacion->fresh('revendedor'),
                        $datos['revendedor_acceso_email'],
                        $passwordTemporal,
                        temporal: true,
                    );

                    app(\App\Services\Auditoria\RegistrarAuditoriaAction::class)->ejecutar(
                        accion: 'password.temporal_generada',
                        entidadTipo: 'revendedor',
                        entidadId: (int) $afiliacion->revendedor_id,
                        datosNuevos: ['email' => $cuenta->email, 'alta' => true],
                    );
                }

                return $afiliacion;
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError('revendedor_' . $campo, $mensajes[0]);
            }

            return;
        }

        if ($this->revendedor_foto) {
            $revendedor = $afiliacion->revendedor()->first();
            $fotoNueva = app(FotoPerfil::class)->guardar($this->revendedor_foto, 'revendedores', $revendedor->foto_url);
            $revendedor->forceFill(['foto_url' => $fotoNueva])->save();
        }

        if ($darAcceso) {
            session()->now('aviso_password_temporal', 'Contraseña temporal para '.trim($datos['revendedor_acceso_email']).': '.$passwordTemporal
                .'. Anótala ahora, no se vuelve a mostrar. Se le pedirá cambiarla al entrar.');
        }

        $this->mostrandoFormularioRevendedor = false;
        $this->revendedorEditandoId = null;
        $this->limpiarAccesoRevendedor();
        $this->cargarRevendedores();
        $this->dispatch('guardado', mensaje: 'Cliente mayorista guardado correctamente.');
    }

    private function limpiarAccesoRevendedor(): void
    {
        $this->revendedor_cuenta_email_actual = null;
        $this->revendedor_acceso_email = null;
        $this->accesoEditadoAMano = false;
        $this->revendedor_foto_actual = null;
        $this->revendedor_foto = null;
    }

};
?>

<div class="mx-auto max-w-5xl space-y-6">
    @if ($seccion === 'empleados')
        <x-panel.encabezado eyebrow="Distribuidora" titulo="Empleados" nivel="h1"
            subtitulo="Personal que entra al panel de tu distribuidora." class="mb-6" />
    @else
        <x-panel.encabezado eyebrow="Distribuidora" titulo="Clientes mayoristas" nivel="h1"
            subtitulo="Revendedores afiliados a tu distribuidora." class="mb-6" />
    @endif


    {{-- Empleados --}}
    @if ($seccion === 'empleados')
    <div class="relative fp-tarjeta p-5 sm:p-6 max-w-3xl fp-aparecer">
        <x-ui.cargando objetivo="guardarPermisos,toggleEstadoEmpleado,gotoPage,nextPage,previousPage" />
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Empleados</h2>
                <button type="button" wire:click="abrirFormularioInvitarEmpleado"
                    class="fp-btn fp-btn-primario">
                    + Invitar empleado
                </button>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2" colspan="2">Nombre</th>
                        <th class="py-2">Correo</th>
                        <th class="py-2">Teléfono</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($empleados as $empleado)
                        <tr class="fp-fila-persona border-b last:border-0 align-top" wire:key="empleado-{{ $empleado->id }}">
                            <td class="w-11 py-2 pr-2"><x-ui.avatar :url="$empleado->usuario?->foto_url" :nombre="$empleado->usuario?->nombreVisible()" /></td>
                            <td class="py-2">{{ $empleado->usuario?->nombreVisible() ?? '—' }}</td>
                            <td class="py-2">
                                {{ $empleado->usuario?->email ?? '—' }}
                                <x-ui.estado-correo :usuario="$empleado->usuario" />
                            </td>
                            <td class="py-2">{{ $empleado->usuario?->telefono ?? '—' }}</td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $empleado->estado === 'activo' ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                    {{ $empleado->estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" wire:click="toggleEstadoEmpleado({{ $empleado->id }})"
                                    wire:confirm="{{ $empleado->estado === 'activo' ? '¿Dar de baja a este empleado? Pierde el acceso al panel de inmediato.' : '¿Reactivar a este empleado?' }}"
                                    class="text-fp-primary text-xs font-medium">
                                    {{ $empleado->estado === 'activo' ? 'Desactivar' : 'Activar' }}
                                </button>
                                <button type="button" wire:click="abrirFormularioEditarEmpleado({{ $empleado->id }})"
                                    class="ml-3 text-fp-primary text-xs font-medium" data-editar="empleado-{{ $empleado->id }}">Editar</button>
                                <button type="button" wire:click="pedirEliminar('empleado', {{ $empleado->id }})"
                                    class="ml-3 text-xs font-medium text-fp-danger transition-opacity hover:opacity-75" data-eliminar="empleado-{{ $empleado->id }}">Eliminar</button>
                                <div class="mt-2 space-y-1 text-left text-xs text-slate-600">
                                    @foreach (\App\Services\Auth\PermisoModuloService::ETIQUETAS_MODULO
                                        + array_map(fn ($p) => $p[0], \App\Services\Auth\PermisoModuloService::PERMISOS_FINOS) as $permiso => $etiqueta)
                                        <label class="flex items-center gap-1 rounded px-1 transition-colors hover:bg-fp-page">
                                            <input type="checkbox" wire:model="permisosEmpleado.{{ $empleado->id }}" value="{{ $permiso }}">
                                            {{ $etiqueta }}
                                        </label>
                                    @endforeach
                                    <button type="button" wire:click="guardarPermisos({{ $empleado->id }})"
                                        class="mt-1 text-fp-primary text-xs font-medium">
                                        Guardar permisos
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
    </div>

    @if ($mostrandoFormularioEmpleado)
        <x-panel.modal :titulo="$empleadoEditandoId ? 'Editar empleado' : 'Invitar empleado'"
            :subtitulo="$empleadoEditandoId ? 'Cambia su nombre, teléfono o foto.' : 'Entrará al panel con este correo y contraseña.'"
            cerrar="cancelarFormularioEmpleado" formulario="guardarEmpleado" ancho="max-w-xl" class="fp-formulario" data-modal="empleado">
            <div class="space-y-4">
                <div class="flex items-center gap-4" data-foto="empleado">
                    @if ($empleado_foto && method_exists($empleado_foto, 'isPreviewable') && $empleado_foto->isPreviewable())
                        <img src="{{ $empleado_foto->temporaryUrl() }}" alt="Vista previa de la foto nueva" class="fp-foto-vista h-16 w-16 rounded-full object-cover ring-2 ring-fp-primary/30">
                    @else
                        <x-ui.avatar :url="$empleado_foto_actual" :nombre="$empleado_nombre" tamano="h-16 w-16 text-base" />
                    @endif
                    <div class="text-sm">
                        <label for="usuarios-empleado-foto" class="fp-btn fp-btn-secundario cursor-pointer focus-within:ring-2 focus-within:ring-fp-primary">
                            <span wire:loading.remove wire:target="empleado_foto">{{ $empleado_foto_actual || $empleado_foto ? 'Cambiar foto' : 'Subir foto' }}</span>
                            <span wire:loading wire:target="empleado_foto">Subiendo…</span>
                            <input id="usuarios-empleado-foto" type="file" wire:model="empleado_foto" accept="image/jpeg,image/png,image/webp" class="sr-only">
                        </label>
                        @if ($empleado_foto)
                            <button type="button" wire:click="quitarFotoNuevaEmpleado" class="ml-2 text-xs font-medium text-slate-600 hover:text-fp-danger">Quitar</button>
                        @endif
                        <p class="mt-1 text-xs text-fp-text-muted">JPG, PNG o WebP, máximo 2 MB.</p>
                        @error('empleado_foto') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="fp-campo">
                        <label for="usuarios-empleado-nombre" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input id="usuarios-empleado-nombre" type="text" wire:model="empleado_nombre" autofocus required class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('empleado_nombre') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-empleado-telefono" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input id="usuarios-empleado-telefono" type="tel" wire:model="empleado_telefono" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('empleado_telefono') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo sm:col-span-2">
                        <label for="usuarios-empleado-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        @if ($empleadoEditandoId)
                            <input id="usuarios-empleado-email" type="email" value="{{ $empleado_email_actual }}" readonly aria-describedby="usuarios-empleado-email-ayuda"
                                class="w-full rounded-lg bg-slate-100 text-sm text-slate-500 shadow-sm">
                            <p id="usuarios-empleado-email-ayuda" class="mt-1 text-xs text-fp-text-muted">Es su correo para entrar al panel; no se cambia aquí.</p>
                        @else
                            <input id="usuarios-empleado-email" type="email" wire:model="empleado_email" required class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            @error('empleado_email') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                        @endif
                    </div>
                    @unless ($empleadoEditandoId)
                        <div class="fp-campo">
                            <label for="usuarios-empleado-password" class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                            <input id="usuarios-empleado-password" type="password" wire:model="empleado_password" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            @error('empleado_password') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                        </div>
                        <div class="fp-campo">
                            <label for="usuarios-empleado-password-confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                            <input id="usuarios-empleado-password-confirmation" type="password" wire:model="empleado_password_confirmation" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        </div>
                    @endunless
                </div>
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cancelarFormularioEmpleado" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarEmpleado,empleado_foto">
                    <span wire:loading.remove wire:target="guardarEmpleado">Guardar</span>
                    <span wire:loading wire:target="guardarEmpleado">Guardando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @endif

    {{-- Revendedores --}}
    @if ($seccion === 'mayoristas')
    <div class="fp-tarjeta p-5 sm:p-6 max-w-3xl fp-aparecer">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Clientes mayoristas</h2>
                <button type="button" wire:click="abrirFormularioAfiliarRevendedor"
                    class="fp-btn fp-btn-primario">
                    + Afiliar cliente mayorista
                </button>
            </div>
        <div class="mb-4 flex flex-wrap items-center gap-2" data-filtros-mayoristas>
            <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Buscar nombre, código, teléfono o correo" etiqueta="Buscar clientes mayoristas" class="min-w-0 flex-1" />
            <select wire:model.live="filtroEstado" aria-label="Filtrar por estado" class="rounded-lg text-sm">
                <option value="">Todos los estados</option>
                <option value="activo">Activo</option>
                <option value="suspendido">Suspendido</option>
                    <option value="inactivo">Inactivo</option>
            </select>
            <button type="button" wire:click="exportar" data-exportar="clientes-mayoristas" class="fp-btn fp-btn-secundario"
                wire:loading.attr="disabled" wire:target="exportar">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                <span wire:loading.remove wire:target="exportar">Exportar Excel</span>
                <span wire:loading wire:target="exportar">Generando…</span>
            </button>
        </div>
            @if (session('aviso_password_temporal'))
                <div class="mb-4 rounded-lg border border-fp-badge-warning-fg/20 bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
                    {{ session('aviso_password_temporal') }}
                </div>
            @endif
            @if ($avisoEnlace)
                <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $avisoEnlaceEsError ? 'border-fp-danger/20 bg-fp-danger-soft text-fp-badge-danger-fg' : 'border-fp-badge-success-fg/20 bg-fp-badge-success-bg text-fp-badge-success-fg' }}">
                    {{ $avisoEnlace }}
                </div>
            @endif
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2" colspan="2">Nombre</th>
                        <th class="py-2">Teléfono</th>
                        <th class="py-2">Correo</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2">App</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($revendedores as $afiliacion)
                        <tr class="fp-fila-persona border-b last:border-0" wire:key="revendedor-{{ $afiliacion->id }}">
                            <td class="w-11 py-2 pr-2"><x-ui.avatar :url="$afiliacion->revendedor->foto_url" :nombre="$afiliacion->revendedor->nombre" /></td>
                            <td class="py-2">
                                {{ $afiliacion->revendedor->nombre }}
                                @if ($afiliacion->codigo_interno)
                                    <span class="block font-mono text-xs text-fp-text-muted">{{ $afiliacion->codigo_interno }}</span>
                                @endif
                            </td>
                            <td class="py-2">{{ $afiliacion->revendedor->telefono }}</td>
                            <td class="py-2">
                                {{ $afiliacion->revendedor->emailVisible() ?: '—' }}
                                <x-ui.estado-correo :usuario="$afiliacion->revendedor->usuario" />
                            </td>
                            <td class="py-2">
                                @php
                                    $colorEstado = match ($afiliacion->estado) {
                                        'activo' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
                                        'suspendido' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
                                        default => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $colorEstado }}">
                                    {{ ucfirst($afiliacion->estado) }}
                                </span>
                            </td>
                            <td class="py-2">
                                @if ($afiliacion->revendedor->usuario_id)
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-fp-badge-success-bg text-fp-badge-success-fg">Con cuenta</span>
                                @else
                                    <span class="text-xs text-slate-500">Sin cuenta</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                @if ($afiliacion->revendedor->usuario_id)
                                    <button type="button" wire:click="enviarEnlaceRevendedor({{ $afiliacion->id }})"
                                        wire:loading.attr="disabled"
                                        class="text-fp-primary text-xs font-medium mr-3">Enviar enlace</button>
                                    @if (! $afiliacion->revendedor->usuario?->hasVerifiedEmail())
                                        <button type="button" wire:click="reenviarVerificacionRevendedor({{ $afiliacion->id }})"
                                            wire:loading.attr="disabled"
                                            class="text-fp-primary text-xs font-medium mr-3">Reenviar confirmación</button>
                                    @endif
                                    <button type="button" wire:click="generarPasswordTemporalRevendedor({{ $afiliacion->id }})"
                                        wire:loading.attr="disabled"
                                        class="text-fp-primary text-xs font-medium mr-3">Contraseña temporal</button>
                                @endif
                                <button type="button" wire:click="abrirFormularioEditarRevendedor({{ $afiliacion->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                                @if ($afiliacion->estado !== 'inactivo')
                                    <button type="button" wire:click="darDeBaja('revendedor', {{ $afiliacion->id }})"
                                        wire:confirm="¿Dar de baja a {{ $afiliacion->revendedor->nombre }}? Ya no podrá entrar a la app; su historial se conserva."
                                        class="ml-3 text-xs font-medium text-fp-danger transition-opacity hover:opacity-75" data-baja="revendedor-{{ $afiliacion->id }}">Dar de baja</button>
                                @else
                                    <button type="button" wire:click="reactivar('revendedor', {{ $afiliacion->id }})"
                                        wire:confirm="¿Reactivar el acceso de {{ $afiliacion->revendedor->nombre }}?"
                                        class="ml-3 text-xs font-medium text-emerald-700 transition-opacity hover:opacity-75">Reactivar</button>
                                @endif
                                <button type="button" wire:click="pedirEliminar('revendedor', {{ $afiliacion->id }})"
                                    class="ml-3 text-xs font-medium text-fp-danger transition-opacity hover:opacity-75" data-eliminar="revendedor-{{ $afiliacion->id }}">Eliminar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
    </div>

    @if ($mostrandoFormularioRevendedor)
        <x-panel.modal :titulo="$revendedorEditandoId ? 'Editar cliente mayorista' : 'Afiliar nuevo cliente mayorista'"
            subtitulo="Sus datos de contacto y, si quieres, acceso a la app."
            cerrar="cancelarFormularioRevendedor" formulario="guardarRevendedor" ancho="max-w-xl" class="fp-formulario" data-modal="revendedor">
            <div class="space-y-4">
                <div class="flex items-center gap-4" data-foto="revendedor">
                    @if ($revendedor_foto && method_exists($revendedor_foto, 'isPreviewable') && $revendedor_foto->isPreviewable())
                        <img src="{{ $revendedor_foto->temporaryUrl() }}" alt="Vista previa de la foto nueva" class="fp-foto-vista h-16 w-16 rounded-full object-cover ring-2 ring-fp-primary/30">
                    @else
                        <x-ui.avatar :url="$revendedor_foto_actual" :nombre="$revendedor_nombre" tamano="h-16 w-16 text-base" />
                    @endif
                    <div class="text-sm">
                        <label for="usuarios-revendedor-foto" class="fp-btn fp-btn-secundario cursor-pointer focus-within:ring-2 focus-within:ring-fp-primary">
                            <span wire:loading.remove wire:target="revendedor_foto">{{ $revendedor_foto_actual || $revendedor_foto ? 'Cambiar foto' : 'Subir foto' }}</span>
                            <span wire:loading wire:target="revendedor_foto">Subiendo…</span>
                            <input id="usuarios-revendedor-foto" type="file" wire:model="revendedor_foto" accept="image/jpeg,image/png,image/webp" class="sr-only">
                        </label>
                        @if ($revendedor_foto)
                            <button type="button" wire:click="quitarFotoNuevaRevendedor" class="ml-2 text-xs font-medium text-slate-600 hover:text-fp-danger">Quitar</button>
                        @endif
                        <p class="mt-1 text-xs text-fp-text-muted">JPG, PNG o WebP, máximo 2 MB.</p>
                        @error('revendedor_foto') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-nombre" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input id="usuarios-revendedor-nombre" type="text" wire:model="revendedor_nombre" autofocus required class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('revendedor_nombre') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-codigo-interno" class="block text-sm font-medium text-slate-700 mb-1">Código interno</label>
                        <input id="usuarios-revendedor-codigo-interno" type="text" value="{{ $revendedor_codigo_interno }}" readonly aria-readonly="true" aria-describedby="usuarios-revendedor-codigo-ayuda"
                            class="w-full rounded-lg bg-slate-100 font-mono text-sm text-slate-600 shadow-sm" data-codigo-interno>
                        <p id="usuarios-revendedor-codigo-ayuda" class="mt-1 text-xs text-fp-text-muted">
                            {{ $revendedorEditandoId ? 'Lo asigna el sistema; no se edita.' : 'Se asigna al guardar.' }}
                        </p>
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-telefono" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input id="usuarios-revendedor-telefono" type="tel" wire:model="revendedor_telefono" placeholder="+52 55 1234 5678" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('revendedor_telefono') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        @if ($revendedor_cuenta_email_actual !== null)
                            <input id="usuarios-revendedor-email" type="email" value="{{ $revendedor_cuenta_email_actual }}" readonly
                                class="w-full rounded-lg bg-slate-100 text-sm text-slate-500 shadow-sm">
                            <p class="mt-1 text-xs text-fp-text-muted">Es su correo para entrar a la app.</p>
                        @else
                            <input id="usuarios-revendedor-email" type="email" wire:model.live.debounce.400ms="revendedor_email" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            @error('revendedor_email') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                        @endif
                    </div>
                </div>
                <div class="border-t border-slate-200 pt-4">
                    <h4 class="text-sm font-semibold text-slate-700 mb-1">Acceso a la app</h4>
                    @if ($revendedor_cuenta_email_actual)
                        <p class="text-sm text-slate-600">
                            Ya tiene cuenta: <span class="font-medium">{{ $revendedor_cuenta_email_actual }}</span>
                        </p>
                    @else
                        <p class="text-xs text-slate-500 mb-3" id="usuarios-revendedor-acceso-ayuda">
                            Opcional. Usamos su correo de contacto (puedes cambiarlo). Al guardar se genera una contraseña temporal que verás una sola vez; se le pedirá cambiarla al entrar.
                        </p>
                        <label for="usuarios-revendedor-acceso-email" class="block text-sm font-medium text-slate-700 mb-1">Correo para entrar a la app</label>
                        <input id="usuarios-revendedor-acceso-email" type="email" wire:model.live.blur="revendedor_acceso_email" autocomplete="off"
                            aria-describedby="usuarios-revendedor-acceso-ayuda" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('revendedor_acceso_email') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                    @endif
                </div>
                @if ($revendedorEditandoId)
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-estado" class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
                        <select id="usuarios-revendedor-estado" wire:model="revendedor_estado" class="w-full rounded-md max-w-xs">
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="inactivo">Inactivo (desafiliado)</option>
                        </select>
                    </div>
                @endif
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cancelarFormularioRevendedor" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarRevendedor,revendedor_foto">
                    <span wire:loading.remove wire:target="guardarRevendedor">Guardar</span>
                    <span wire:loading wire:target="guardarRevendedor">Guardando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @endif

    @if ($porEliminar)
        <x-panel.modal titulo="Eliminar definitivamente" cerrar="cancelarEliminar" ancho="max-w-md" data-modal="eliminar">
            <p class="text-sm text-slate-700">
                Vas a borrar a <span class="font-semibold">{{ $porEliminar['nombre'] }}</span> para siempre.
                No se puede deshacer. Si tiene pedidos u otros movimientos no se podrá eliminar: en ese caso dale de baja.
            </p>
            @if ($errorEliminar)
                <div class="fp-emerger mt-4 rounded-lg border border-fp-danger/20 bg-fp-danger-soft px-4 py-3 text-sm text-fp-badge-danger-fg" role="alert" data-error-eliminar>
                    {{ $errorEliminar }}
                </div>
            @endif
            <x-slot:pie>
                <button type="button" wire:click="cancelarEliminar" class="fp-btn fp-btn-secundario" autofocus>Cancelar</button>
                <button type="button" wire:click="eliminarDefinitivamente" class="fp-btn fp-btn-peligro"
                    wire:loading.attr="disabled" wire:target="eliminarDefinitivamente" @disabled($errorEliminar)>
                    <span wire:loading.remove wire:target="eliminarDefinitivamente">Eliminar definitivamente</span>
                    <span wire:loading wire:target="eliminarDefinitivamente">Eliminando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
