<?php

use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Auth\LimiteIntentosLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Iniciar sesión — FootwearPoint')] class extends Component {
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function mount()
    {
        if (!Auth::check()) {
            return;
        }

        $usuario = Auth::user();
        $acceso = app(AccesoPanelWebService::class);

        // TG-184: el panel es solo para el personal. Si quedo una sesion de
        // un revendedor o un cliente (de antes de esta correccion), se cierra
        // y se le dice que entre por la app, en vez de mandarlo al panel.
        // TG-195: lo mismo con el personal de una distribuidora rechazada.
        $motivo = $acceso->motivoSinAcceso($usuario);

        if ($motivo !== null) {
            $this->cerrarSesionAjena($motivo);

            return;
        }

        $staff = $acceso->staffActivo($usuario);

        if ($staff) {
            setPermissionsTeamId($staff->distribuidora_id);
        } else {
            setPermissionsTeamId(0);
        }

        if ($usuario->hasRole('admin_general')) {
            return $this->redirect(route('admin.dashboard'), navigate: true);
        }

        return $this->redirect(route('dashboard'), navigate: true);
    }

    /** Cierra la sesion de quien no puede usar el panel y deja el aviso a la vista. */
    private function cerrarSesionAjena(string $motivo): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        session()->flash('aviso_acceso', $motivo);
    }

    protected function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    public function login()
    {
        $this->validate();

        $limite = app(LimiteIntentosLogin::class);
        $ip = request()->ip();

        // TG-185: sin esto se podian probar contrasenas sin limite. Se revisa
        // antes de mirar la contrasena, para que el bloqueo frene de verdad.
        if ($limite->bloqueado($this->email, $ip)) {
            $this->addError('email', $limite->mensaje($limite->segundosRestantes($this->email, $ip)));

            return;
        }

        $usuario = Usuario::where('email', $this->email)->first();

        if (!$usuario || !Hash::check($this->password, $usuario->password)) {
            $limite->registrarFallo($this->email, $ip);

            $this->addError('email', 'Las credenciales son incorrectas.');
            return;
        }

        // Entro bien: los fallos anteriores ya no cuentan.
        $limite->limpiar($this->email, $ip);

        if ($usuario->estado !== 'activo') {
            $this->addError('email', 'Tu cuenta no está activa.');
            return;
        }

        $acceso = app(AccesoPanelWebService::class);

        // TG-184: revendedores y clientes entran por la app, no por aqui. Se
        // revisa ANTES de crear la sesion, para no dejarles una sesion valida
        // a quien se esta rechazando (igual que hace la API en
        // AuthController::login con el personal).
        // TG-195: tampoco entra el personal de una distribuidora rechazada.
        $motivo = $acceso->motivoSinAcceso($usuario);

        if ($motivo !== null) {
            $this->addError('email', $motivo);

            return;
        }

        Auth::login($usuario, $this->remember);
        session()->regenerate();

        // Solo el personal activo da contexto de distribuidora: un empleado
        // desactivado ya no lo toma (antes se buscaba sin mirar su estado).
        $staff = $acceso->staffActivo($usuario);

        if ($staff) {
            setPermissionsTeamId($staff->distribuidora_id);
        } else {
            setPermissionsTeamId(0);
        }

        if ($usuario->hasRole('admin_general')) {
            return $this->redirect(route('admin.dashboard'), navigate: true);
        }

        return $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
        <div class="text-center mb-8">
        <img
            src="{{ asset('brand/logo-full-160.png') }}"
            alt="Footwear Point"
            class="mx-auto h-28 w-auto object-contain"
        >
        <p class="text-sm text-slate-500 mt-3">Inicia sesión en tu cuenta</p>
    </div>

    {{-- TG-184: a quien no es personal se le explica que entre por la app. --}}
    @if (session('aviso_acceso'))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ session('aviso_acceso') }}
        </div>
    @endif

    <form wire:submit="login" class="space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input type="email" wire:model="email"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="correo@ejemplo.com" autofocus>
            @error('email')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
            <input type="password" wire:model="password"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="••••••••">
            @error('password')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="remember"
                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                Recordarme
            </label>

            <a href="{{ route('password.request') }}" class="text-sm text-blue-700 hover:underline">
                ¿Olvidaste tu contraseña?
            </a>
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
            wire:loading.attr="disabled">
            <span wire:loading.remove>Entrar</span>
            <span wire:loading>Entrando...</span>
        </button>
    </form>
</div>
