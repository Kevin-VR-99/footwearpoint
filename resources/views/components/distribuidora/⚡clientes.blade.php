<?php

use App\Models\ClienteDirecto;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    public $clientesDirectos = [];
    public ?int $clienteEditandoId = null;
    public bool $mostrandoFormularioCliente = false;
    public string $cliente_nombre = '';
    public ?string $cliente_telefono = null;
    public ?string $cliente_email = null;
    public ?string $cliente_direccion_contacto = null;
    public string $cliente_estado = 'activo';

    // Cuenta para la app móvil (E3-07 / TG-133), igual que en revendedores.
    public ?string $cliente_cuenta_email_actual = null;
    public ?string $cliente_acceso_email = null;
    public string $cliente_acceso_password = '';
    public string $cliente_acceso_password_confirmation = '';

    public function mount(): void
    {
        $this->cargarClientesDirectos();
    }

    private function cargarClientesDirectos(): void
    {
        $this->clientesDirectos = ClienteDirecto::with('usuario')->get();
    }

    public function abrirFormularioCrearCliente(): void
    {
        $this->clienteEditandoId = null;
        $this->cliente_nombre = '';
        $this->cliente_telefono = null;
        $this->cliente_email = null;
        $this->cliente_direccion_contacto = null;
        $this->cliente_estado = 'activo';
        $this->limpiarAccesoCliente();
        $this->mostrandoFormularioCliente = true;
    }

    public function abrirFormularioEditarCliente(int $id): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($id);
        $this->clienteEditandoId = $cliente->id;
        $this->cliente_nombre = $cliente->nombre;
        $this->cliente_telefono = $cliente->telefono;
        $this->cliente_email = $cliente->email;
        $this->cliente_direccion_contacto = $cliente->direccion_contacto;
        $this->cliente_estado = $cliente->estado;
        $this->limpiarAccesoCliente();
        $this->cliente_cuenta_email_actual = $cliente->usuario?->email;
        $this->mostrandoFormularioCliente = true;
    }

    public function cancelarFormularioCliente(): void
    {
        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
        $this->limpiarAccesoCliente();
    }

    public function guardarCliente(): void
    {
        $datos = $this->validate([
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'cliente_email' => ['nullable', 'email', 'max:190'],
            'cliente_direccion_contacto' => ['nullable', 'string', 'max:300'],
            // E3-07: opcionales, pero si se llena uno se exige el otro.
            'cliente_acceso_email' => ['nullable', 'required_with:cliente_acceso_password', 'email', 'max:190'],
            'cliente_acceso_password' => ['nullable', 'required_with:cliente_acceso_email', 'string', 'min:8', 'same:cliente_acceso_password_confirmation'],
        ], [
            'cliente_acceso_email.required_with' => 'Para dar acceso a la app escribe también el correo.',
            'cliente_acceso_password.required_with' => 'Para dar acceso a la app escribe también la contraseña.',
            'cliente_acceso_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'cliente_acceso_password.same' => 'La confirmación de la contraseña no coincide.',
        ]);

        $payload = [
            'nombre' => $datos['cliente_nombre'],
            'telefono' => $datos['cliente_telefono'],
            'email' => $datos['cliente_email'],
            'direccion_contacto' => $datos['cliente_direccion_contacto'],
            'estado' => $this->cliente_estado,
        ];

        $accion = app(GestionarClienteDirectoAction::class);

        // Una sola transacción: si la cuenta falla (correo repetido), tampoco
        // se crea el cliente, para no dejar un duplicado en la lista.
        try {
            DB::transaction(function () use ($accion, $payload, $datos) {
                $cliente = $this->clienteEditandoId
                    ? $accion->actualizar(ClienteDirecto::findOrFail($this->clienteEditandoId), $payload)
                    : $accion->crear($payload);

                if (filled($datos['cliente_acceso_email']) && $this->cliente_cuenta_email_actual === null) {
                    app(ActivarCuentaAccesoAction::class)->paraClienteDirecto(
                        $cliente->fresh(),
                        $datos['cliente_acceso_email'],
                        $datos['cliente_acceso_password'],
                    );
                }
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError('cliente_' . $campo, $mensajes[0]);
            }

            return;
        }

        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
        $this->limpiarAccesoCliente();
        $this->cargarClientesDirectos();
        $this->dispatch('guardado', mensaje: 'Cliente directo guardado correctamente.');
    }

    /** Las contraseñas no se quedan guardadas en el estado de la pantalla. */
    private function limpiarAccesoCliente(): void
    {
        $this->cliente_cuenta_email_actual = null;
        $this->cliente_acceso_email = null;
        $this->cliente_acceso_password = '';
        $this->cliente_acceso_password_confirmation = '';
    }

};
?>

<div>
    @if (! $mostrandoFormularioCliente)
        <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Clientes Directos</h2>
                <button type="button" wire:click="abrirFormularioCrearCliente"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nuevo cliente</button>
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
                    @foreach ($clientesDirectos as $cliente)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $cliente->nombre }}</td>
                            <td class="py-2">{{ $cliente->telefono }}</td>
                            <td class="py-2">{{ $cliente->email }}</td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $cliente->estado === 'activo' ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                    {{ $cliente->estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="py-2">
                                @if ($cliente->usuario_id)
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-fp-badge-success-bg text-fp-badge-success-fg">Con cuenta</span>
                                @else
                                    <span class="text-xs text-slate-400">Sin cuenta</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" wire:click="abrirFormularioEditarCliente({{ $cliente->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarCliente" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">
                {{ $clienteEditandoId ? 'Editar cliente directo' : 'Nuevo cliente directo' }}
            </h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                <input type="text" wire:model="cliente_nombre" class="w-full rounded-md border-slate-300">
                @error('cliente_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                    <input type="text" wire:model="cliente_telefono" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                    <input type="email" wire:model="cliente_email" class="w-full rounded-md border-slate-300">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Dirección de contacto</label>
                <input type="text" wire:model="cliente_direccion_contacto" class="w-full rounded-md border-slate-300">
            </div>
            {{-- E3-07 (TG-133): cuenta para entrar a la app móvil --}}
            <div class="border-t border-slate-200 pt-4">
                <h3 class="text-sm font-semibold text-slate-700 mb-1">Acceso a la app</h3>
                @if ($cliente_cuenta_email_actual)
                    <p class="text-sm text-slate-600">
                        Ya tiene cuenta: <span class="font-medium">{{ $cliente_cuenta_email_actual }}</span>
                    </p>
                @else
                    <p class="text-xs text-slate-500 mb-3">
                        Opcional. Si le das un correo y una contraseña, podrá entrar a la app móvil con ellos.
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Correo para entrar a la app</label>
                            <input type="email" wire:model="cliente_acceso_email" autocomplete="off" class="w-full rounded-md border-slate-300">
                            @error('cliente_acceso_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                            <input type="password" wire:model="cliente_acceso_password" autocomplete="new-password" class="w-full rounded-md border-slate-300">
                            @error('cliente_acceso_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                            <input type="password" wire:model="cliente_acceso_password_confirmation" autocomplete="new-password" class="w-full rounded-md border-slate-300">
                        </div>
                    </div>
                @endif
            </div>
            @if ($clienteEditandoId)
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
                    <select wire:model="cliente_estado" class="w-full rounded-md border-slate-300 max-w-xs">
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
            @endif
            <div class="flex gap-2">
                <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCliente" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
