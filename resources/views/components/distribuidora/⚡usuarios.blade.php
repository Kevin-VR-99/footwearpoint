<?php

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
use Spatie\Permission\PermissionRegistrar;

new #[Layout('layouts.panel')] #[Title('Usuarios — FootwearPoint')] class extends Component {
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
    public string $revendedor_acceso_password = '';
    public string $revendedor_acceso_password_confirmation = '';

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
                $this->permisosEmpleado[$empleado->id] = $usuario->getDirectPermissions()->pluck('name')->all();
            }
        });
    }

    private function cargarRevendedores(): void
    {
        $this->revendedores = RevendedorDistribuidora::with('revendedor.usuario')->get();
    }

    public function toggleEstadoEmpleado(int $id): void
    {
        $empleado = DistribuidoraStaff::findOrFail($id);
        $empleado->estado = $empleado->estado === 'activo' ? 'inactivo' : 'activo';
        $empleado->save();
        $this->cargarEmpleados();
    }

    public function guardarPermisos(int $id): void
    {
        $empleado = DistribuidoraStaff::with('usuario')->findOrFail($id);
        $usuario = $empleado->usuario;
        abort_if(! $usuario, 404);

        $marcados = $this->permisosEmpleado[$id] ?? [];
        $validos = array_values(array_intersect($marcados, RolesPermissionsSeeder::PERMISOS_POR_MODULO));

        $this->enEquipo(function () use ($usuario, $validos) {
            $usuario->syncPermissions($validos);
        });

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
        $this->empleado_nombre = '';
        $this->empleado_email = '';
        $this->empleado_telefono = null;
        $this->empleado_password = '';
        $this->empleado_password_confirmation = '';
        $this->resetErrorBag();
        $this->mostrandoFormularioEmpleado = true;
    }

    public function cancelarFormularioEmpleado(): void
    {
        $this->mostrandoFormularioEmpleado = false;
        $this->resetErrorBag();
    }

    public function guardarEmpleado(): void
    {
        $this->validate([
            'empleado_nombre' => ['required', 'string', 'max:150'],
            'empleado_email' => ['required', 'email', 'max:190', 'unique:usuarios,email'],
            'empleado_telefono' => ['nullable', 'string', 'max:30'],
            'empleado_password' => ['required', 'string', 'min:8', 'max:255', 'same:empleado_password_confirmation'],
        ], [
            'empleado_password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'empleado_nombre.required' => 'El nombre es obligatorio.',
            'empleado_email.required' => 'El correo es obligatorio.',
            'empleado_email.unique' => 'Ese correo ya está registrado.',
            'empleado_password.required' => 'La contraseña es obligatoria.',
            'empleado_password.min' => 'Mínimo 8 caracteres.',
            'empleado_password.same' => 'Las contraseñas no coinciden.',
        ]);

        $distribuidoraId = Tenant::id();
        abort_if($distribuidoraId === null, 403);

        DB::transaction(function () use ($distribuidoraId) {
            $usuario = Usuario::create([
                'nombre' => $this->empleado_nombre,
                'email' => $this->empleado_email,
                'password' => Hash::make($this->empleado_password),
                'telefono' => $this->empleado_telefono ?: null,
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
        $this->cargarEmpleados();
        $this->dispatch('guardado', mensaje: 'Empleado registrado correctamente.');
    }

    public function abrirFormularioAfiliarRevendedor(): void
    {
        $this->revendedorEditandoId = null;
        $this->revendedor_nombre = '';
        $this->revendedor_telefono = null;
        $this->revendedor_email = null;
        $this->revendedor_codigo_interno = null;
        $this->revendedor_estado = 'activo';
        $this->limpiarAccesoRevendedor();
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

    public function guardarRevendedor(): void
    {
        $datos = $this->validate([
            'revendedor_nombre' => ['required', 'string', 'max:150'],
            'revendedor_telefono' => ['nullable', 'string', 'max:30'],
            'revendedor_email' => ['nullable', 'email', 'max:190'],
            'revendedor_codigo_interno' => ['nullable', 'string', 'max:60'],
            'revendedor_acceso_email' => ['nullable', 'required_with:revendedor_acceso_password', 'email', 'max:190'],
            'revendedor_acceso_password' => ['nullable', 'required_with:revendedor_acceso_email', 'string', 'min:8', 'max:255', 'same:revendedor_acceso_password_confirmation'],
        ], [
            'revendedor_acceso_password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'revendedor_acceso_email.required_with' => 'Para dar acceso a la app escribe también el correo.',
            'revendedor_acceso_password.required_with' => 'Para dar acceso a la app escribe también la contraseña.',
            'revendedor_acceso_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'revendedor_acceso_password.same' => 'La confirmación de la contraseña no coincide.',
        ]);

        $payload = [
            'nombre' => $datos['revendedor_nombre'],
            'telefono' => $datos['revendedor_telefono'],
            ...($this->revendedor_cuenta_email_actual === null
                ? ['email' => $datos['revendedor_email']]
                : []),
            'codigo_interno' => $datos['revendedor_codigo_interno'],
            'estado' => $this->revendedor_estado,
        ];

        $accion = app(GestionarRevendedorAction::class);

        try {
            DB::transaction(function () use ($accion, $payload, $datos) {
                $afiliacion = $this->revendedorEditandoId
                    ? $accion->actualizar(RevendedorDistribuidora::findOrFail($this->revendedorEditandoId), $payload)
                    : $accion->afiliar($payload);

                if (filled($datos['revendedor_acceso_email']) && $this->revendedor_cuenta_email_actual === null) {
                    app(ActivarCuentaAccesoAction::class)->paraRevendedor(
                        $afiliacion->fresh('revendedor'),
                        $datos['revendedor_acceso_email'],
                        $datos['revendedor_acceso_password'],
                    );
                }
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError('revendedor_' . $campo, $mensajes[0]);
            }

            return;
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
        $this->revendedor_acceso_password = '';
        $this->revendedor_acceso_password_confirmation = '';
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

    {{-- Empleados: listado o formulario inline --}}
    @if ($seccion === 'empleados')
    <div class="relative fp-tarjeta p-5 sm:p-6 max-w-3xl fp-formulario fp-aparecer">
        <x-ui.cargando />
        @if (! $mostrandoFormularioEmpleado)
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
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Correo</th>
                        <th class="py-2">Teléfono</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($empleados as $empleado)
                        <tr class="border-b last:border-0 align-top">
                            <td class="py-2">{{ $empleado->usuario?->nombreVisible() ?? '—' }}</td>
                            <td class="py-2">{{ $empleado->usuario?->email ?? '—' }}</td>
                            <td class="py-2">{{ $empleado->usuario?->telefono ?? '—' }}</td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $empleado->estado === 'activo' ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                    {{ $empleado->estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" wire:click="toggleEstadoEmpleado({{ $empleado->id }})"
                                    class="text-fp-primary text-xs font-medium">
                                    {{ $empleado->estado === 'activo' ? 'Desactivar' : 'Activar' }}
                                </button>
                                <div class="mt-2 space-y-1 text-left text-xs text-slate-600">
                                    @foreach ([
                                        'ciclo.cerrar' => 'Cerrar ciclo',
                                        'ciclo.solicitar_fabrica' => 'Solicitar a fábrica',
                                        'reportes.ver_financieros' => 'Reportes financieros',
                                        'configuracion.administrar' => 'Configuración',
                                        'usuarios.administrar' => 'Usuarios',
                                    ] as $permiso => $etiqueta)
                                        <label class="flex items-center gap-1">
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
        @else
            <form wire:submit="guardarEmpleado" class="space-y-4">
                <h2 class="text-sm font-semibold text-slate-700">Invitar empleado</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="fp-campo">
                        <label for="usuarios-empleado-nombre" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input id="usuarios-empleado-nombre" type="text" wire:model="empleado_nombre" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('empleado_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-empleado-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        <input id="usuarios-empleado-email" type="email" wire:model="empleado_email" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('empleado_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-empleado-telefono" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input id="usuarios-empleado-telefono" type="text" wire:model="empleado_telefono" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    </div>
                    <div></div>
                    <div class="fp-campo">
                        <label for="usuarios-empleado-password" class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                        <input id="usuarios-empleado-password" type="password" wire:model="empleado_password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('empleado_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-empleado-password-confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                        <input id="usuarios-empleado-password-confirmation" type="password" wire:model="empleado_password_confirmation" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioEmpleado" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
    @endif

    {{-- Revendedores --}}
    @if ($seccion === 'mayoristas')
    <div class="fp-tarjeta p-5 sm:p-6 max-w-3xl fp-formulario fp-aparecer">
        @if (! $mostrandoFormularioRevendedor)
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Clientes mayoristas</h2>
                <button type="button" wire:click="abrirFormularioAfiliarRevendedor"
                    class="fp-btn fp-btn-primario">
                    + Afiliar cliente mayorista
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
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Teléfono</th>
                        <th class="py-2">Correo</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2">App</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($revendedores as $afiliacion)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $afiliacion->revendedor->nombre }}</td>
                            <td class="py-2">{{ $afiliacion->revendedor->telefono }}</td>
                            <td class="py-2">{{ $afiliacion->revendedor->email }}</td>
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
                                    @if (! $afiliacion->revendedor->usuario?->hasVerifiedEmail())
                                        <span class="mt-1 block text-xs text-amber-700">Correo sin confirmar</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-400">Sin cuenta</span>
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
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <form wire:submit="guardarRevendedor" class="space-y-4">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $revendedorEditandoId ? 'Editar cliente mayorista' : 'Afiliar nuevo cliente mayorista' }}
                </h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-nombre" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input id="usuarios-revendedor-nombre" type="text" wire:model="revendedor_nombre" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @error('revendedor_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-codigo-interno" class="block text-sm font-medium text-slate-700 mb-1">Código interno</label>
                        <input id="usuarios-revendedor-codigo-interno" type="text" wire:model="revendedor_codigo_interno" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    </div>
                    <div class="fp-campo">
                        <label for="usuarios-revendedor-telefono" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input id="usuarios-revendedor-telefono" type="text" wire:model="revendedor_telefono" placeholder="+52 55 1234 5678" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    </div>
                    <div class="fp-campo">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        @if ($revendedor_cuenta_email_actual !== null)
                            <input type="email" value="{{ $revendedor_cuenta_email_actual }}" disabled
                                class="w-full rounded-lg bg-slate-100 text-sm text-slate-500 shadow-sm">
                            <p class="mt-1 text-xs text-fp-text-muted">Es su correo para entrar a la app.</p>
                        @else
                            <input type="email" wire:model="revendedor_email" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                        @endif
                    </div>
                </div>
                <div class="border-t border-slate-200 pt-4">
                    <h3 class="text-sm font-semibold text-slate-700 mb-1">Acceso a la app</h3>
                    @if ($revendedor_cuenta_email_actual)
                        <p class="text-sm text-slate-600">
                            Ya tiene cuenta: <span class="font-medium">{{ $revendedor_cuenta_email_actual }}</span>
                        </p>
                    @else
                        <p class="text-xs text-slate-500 mb-3">
                            Opcional. Si le das un correo y una contraseña, podrá entrar a la app móvil con ellos.
                        </p>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label for="usuarios-revendedor-acceso-email" class="block text-sm font-medium text-slate-700 mb-1">Correo para entrar a la app</label>
                                <input id="usuarios-revendedor-acceso-email" type="email" wire:model="revendedor_acceso_email" autocomplete="off" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                                @error('revendedor_acceso_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="usuarios-revendedor-acceso-password" class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                                <input id="usuarios-revendedor-acceso-password" type="password" wire:model="revendedor_acceso_password" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                                @error('revendedor_acceso_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="usuarios-revendedor-acceso-password-confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                                <input id="usuarios-revendedor-acceso-password-confirmation" type="password" wire:model="revendedor_acceso_password_confirmation" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            </div>
                        </div>
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
                <div class="flex gap-2">
                    <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioRevendedor" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
    @endif
</div>