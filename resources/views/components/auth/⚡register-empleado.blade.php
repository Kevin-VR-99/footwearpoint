<?php

use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Registrar empleado — FootwearPoint')] class extends Component
{
    public string $nombre = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $telefono = '';
    public string $mensaje = '';

    public function mount()
    {
        $user = Auth::user();

        if (!$user) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(
            DistribuidoraStaff::withoutGlobalScopes()
                ->where('usuario_id', $user->id)
                ->value('distribuidora_id') ?? 0
        );

        if (!$user->hasRole('admin_distribuidora')) {
            abort(403, 'No autorizado.');
        }
    }

    protected function rules(): array
    {
        return [
            'nombre'   => ['required', 'string', 'max:150'],
            'email'    => ['required', 'email', 'max:190', 'unique:usuarios,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nombre.required'    => 'El nombre es obligatorio.',
            'email.required'     => 'El correo es obligatorio.',
            'email.unique'       => 'Ese correo ya está registrado.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'Mínimo 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }

    public function registrar()
    {
        $this->validate();
        $this->mensaje = '';

        $admin = Auth::user();

        $staffAdmin = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $admin->id)
            ->first();

        if (!$staffAdmin) {
            $this->addError('email', 'No se encontró distribuidora asociada.');
            return;
        }

        $distribuidoraId = $staffAdmin->distribuidora_id;

        DB::transaction(function () use ($distribuidoraId) {
            $usuario = Usuario::create([
                'nombre'   => $this->nombre,
                'email'    => $this->email,
                'password' => Hash::make($this->password),
                'telefono' => $this->telefono ?: null,
                'estado'   => 'activo',
            ]);

            DistribuidoraStaff::withoutGlobalScopes()->create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id'       => $usuario->id,
                'tipo'             => 'empleado',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]);

            setPermissionsTeamId($distribuidoraId);
            $usuario->assignRole('empleado');
        });

        $this->reset(['nombre', 'email', 'password', 'password_confirmation', 'telefono']);
        $this->mensaje = 'Empleado registrado correctamente.';
    }
};
?>

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Registrar empleado</h1>
        <p class="text-sm text-slate-500 mt-1">Alta de personal de la distribuidora</p>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    <form wire:submit="registrar" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
            <input type="text" wire:model="nombre"
                   class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600">
            @error('nombre') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input type="email" wire:model="email"
                   class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600">
            @error('email') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
            <input type="text" wire:model="telefono"
                   class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600">
            @error('telefono') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
            <input type="password" wire:model="password"
                   class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600">
            @error('password') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
            <input type="password" wire:model="password_confirmation"
                   class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
                wire:loading.attr="disabled">
            <span wire:loading.remove>Registrar empleado</span>
            <span wire:loading>Guardando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-700 hover:underline">
                Volver al dashboard
            </a>
        </div>
    </form>
</div>