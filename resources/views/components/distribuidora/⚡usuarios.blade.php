<?php

use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    public $empleados = [];
    public bool $mostrandoFormularioEmpleado = false;
    public string $empleado_nombre = '';
    public string $empleado_email = '';
    public ?string $empleado_telefono = null;
    public string $empleado_password = '';
    public string $empleado_password_confirmation = '';

    public $revendedores = [];
    public ?int $revendedorEditandoId = null;
    public bool $mostrandoFormularioRevendedor = false;
    public string $revendedor_nombre = '';
    public ?string $revendedor_telefono = null;
    public ?string $revendedor_email = null;
    public ?string $revendedor_codigo_interno = null;
    public string $revendedor_estado = 'activo';

    // Cuenta para la app móvil (E3-07 / TG-133). Si ya tiene, se guarda su
    // correo para mostrarlo; si no, se capturan los campos de acceso.
    public ?string $revendedor_cuenta_email_actual = null;
    public ?string $revendedor_acceso_email = null;
    public string $revendedor_acceso_password = '';
    public string $revendedor_acceso_password_confirmation = '';

    public function mount(): void
    {
        $this->cargarEmpleados();
        $this->cargarRevendedores();
    }

    private function cargarEmpleados(): void
    {
        $this->empleados = DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->get();
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
            'empleado_password' => ['required', 'string', 'min:8', 'same:empleado_password_confirmation'],
        ], [
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

            DistribuidoraStaff::withoutGlobalScopes()->create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id' => $usuario->id,
                'tipo' => 'empleado',
                'estado' => 'activo',
                'fecha_alta' => now(),
            ]);

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
            // E3-07: opcionales, pero si se llena uno se exige el otro.
            'revendedor_acceso_email' => ['nullable', 'required_with:revendedor_acceso_password', 'email', 'max:190'],
            'revendedor_acceso_password' => ['nullable', 'required_with:revendedor_acceso_email', 'string', 'min:8', 'same:revendedor_acceso_password_confirmation'],
        ], [
            'revendedor_acceso_email.required_with' => 'Para dar acceso a la app escribe también el correo.',
            'revendedor_acceso_password.required_with' => 'Para dar acceso a la app escribe también la contraseña.',
            'revendedor_acceso_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'revendedor_acceso_password.same' => 'La confirmación de la contraseña no coincide.',
        ]);

        $payload = [
            'nombre' => $datos['revendedor_nombre'],
            'telefono' => $datos['revendedor_telefono'],
            'email' => $datos['revendedor_email'],
            'codigo_interno' => $datos['revendedor_codigo_interno'],
            'estado' => $this->revendedor_estado,
        ];

        $accion = app(GestionarRevendedorAction::class);

        // Una sola transacción: si la cuenta falla (correo repetido), tampoco
        // se afilia al revendedor, para no dejar un duplicado en la lista.
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
            // La acción nombra el campo "acceso_email"; aquí se llama
            // "revendedor_acceso_email", para que el error salga debajo.
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError('revendedor_' . $campo, $mensajes[0]);
            }

            return;
        }

        $this->mostrandoFormularioRevendedor = false;
        $this->revendedorEditandoId = null;
        $this->limpiarAccesoRevendedor();
        $this->cargarRevendedores();
        $this->dispatch('guardado', mensaje: 'Revendedor guardado correctamente.');
    }

    /** Las contraseñas no se quedan guardadas en el estado de la pantalla. */
    private function limpiarAccesoRevendedor(): void
    {
        $this->revendedor_cuenta_email_actual = null;
        $this->revendedor_acceso_email = null;
        $this->revendedor_acceso_password = '';
        $this->revendedor_acceso_password_confirmation = '';
    }

};
?>

<div class="space-y-6">

    {{-- Empleados: listado o formulario inline --}}
    <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
        @if (! $mostrandoFormularioEmpleado)
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Empleados</h2>
                <button type="button" wire:click="abrirFormularioInvitarEmpleado"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
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
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $empleado->usuario?->nombre ?? '—' }}</td>
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
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <form wire:submit="guardarEmpleado" class="space-y-4">
                <h2 class="text-sm font-semibold text-slate-700">Invitar empleado</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input type="text" wire:model="empleado_nombre" class="w-full rounded-md border-slate-300">
                        @error('empleado_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        <input type="email" wire:model="empleado_email" class="w-full rounded-md border-slate-300">
                        @error('empleado_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input type="text" wire:model="empleado_telefono" class="w-full rounded-md border-slate-300">
                    </div>
                    <div></div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                        <input type="password" wire:model="empleado_password" class="w-full rounded-md border-slate-300">
                        @error('empleado_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                        <input type="password" wire:model="empleado_password_confirmation" class="w-full rounded-md border-slate-300">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioEmpleado" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Revendedores --}}
    <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
        @if (! $mostrandoFormularioRevendedor)
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Revendedores</h2>
                <button type="button" wire:click="abrirFormularioAfiliarRevendedor"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                    + Afiliar revendedor
                </button>
            </div>
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
                                @else
                                    <span class="text-xs text-slate-400">Sin cuenta</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">
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
                    {{ $revendedorEditandoId ? 'Editar revendedor' : 'Afiliar nuevo revendedor' }}
                </h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                        <input type="text" wire:model="revendedor_nombre" class="w-full rounded-md border-slate-300">
                        @error('revendedor_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Código interno</label>
                        <input type="text" wire:model="revendedor_codigo_interno" class="w-full rounded-md border-slate-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input type="text" wire:model="revendedor_telefono" placeholder="+52 55 1234 5678" class="w-full rounded-md border-slate-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        <input type="email" wire:model="revendedor_email" class="w-full rounded-md border-slate-300">
                    </div>
                </div>
                {{-- E3-07 (TG-133): cuenta para entrar a la app móvil --}}
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
                                <label class="block text-sm font-medium text-slate-700 mb-1">Correo para entrar a la app</label>
                                <input type="email" wire:model="revendedor_acceso_email" autocomplete="off" class="w-full rounded-md border-slate-300">
                                @error('revendedor_acceso_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                                <input type="password" wire:model="revendedor_acceso_password" autocomplete="new-password" class="w-full rounded-md border-slate-300">
                                @error('revendedor_acceso_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                                <input type="password" wire:model="revendedor_acceso_password_confirmation" autocomplete="new-password" class="w-full rounded-md border-slate-300">
                            </div>
                        </div>
                    @endif
                </div>
                @if ($revendedorEditandoId)
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
                        <select wire:model="revendedor_estado" class="w-full rounded-md border-slate-300 max-w-xs">
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="inactivo">Inactivo (desafiliado)</option>
                        </select>
                    </div>
                @endif
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioRevendedor" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
</div>
