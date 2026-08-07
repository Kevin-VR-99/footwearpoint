<?php

use App\Models\ClienteDirecto;
use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Services\Distribuidora\ConfiguracionCicloAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel')] class extends Component {
    use WithFileUploads;

    public string $pestanaActiva = 'perfil';

    // --- Perfil ---
    public string $nombre_comercial = '';
    public ?string $descripcion_publica = null;
    public ?string $direccion_publica = null;
    public ?string $telefono_publico = null;
    public ?string $email_publico = null;
    public ?string $horario_publico = null;
    public $logotipo = null;
    public ?string $logotipo_url_actual = null;

    // --- Configuración general ---
    public float $anticipo_por_producto = 0;
    public int $dias_solicitud_cambio = 12;
    public int $dias_gestion_devolucion = 20;
    public int $dias_vigencia_vale = 90;
    public int $dias_maximos_recoleccion = 5;
    public string $moneda = 'MXN';
    public string $zona_horaria = 'America/Mexico_City';

    // --- Ciclos ---
    public $ciclos = [];
    public ?int $cicloEditandoId = null;
    public bool $mostrandoFormularioCiclo = false;
    public int $ciclo_dia_cierre = 5;
    public string $ciclo_hora_cierre = '18:00';
    public int $ciclo_dia_solicitud_fabrica = 5;
    public int $ciclo_dias_estimados_llegada = 5;
    public bool $ciclo_activa = true;
    public array $ciclo_dias_recepcion = [];
    public array $diasSemanaNombres = [];

    private const DIAS_SEMANA = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    // --- Empleados ---
    public $empleados = [];
    public bool $mostrandoFormularioEmpleado = false;
    public string $empleado_nombre = '';
    public string $empleado_email = '';
    public ?string $empleado_telefono = null;
    public string $empleado_password = '';
    public string $empleado_password_confirmation = '';

    // --- Revendedores ---
    public $revendedores = [];
    public ?int $revendedorEditandoId = null;
    public bool $mostrandoFormularioRevendedor = false;
    public string $revendedor_nombre = '';
    public ?string $revendedor_telefono = null;
    public ?string $revendedor_email = null;
    public ?string $revendedor_codigo_interno = null;
    public string $revendedor_estado = 'activo';

    // --- Clientes ---
    public $clientesDirectos = [];
    public ?int $clienteEditandoId = null;
    public bool $mostrandoFormularioCliente = false;
    public string $cliente_nombre = '';
    public ?string $cliente_telefono = null;
    public ?string $cliente_email = null;
    public ?string $cliente_direccion_contacto = null;
    public string $cliente_estado = 'activo';

    public function mount(): void
    {
        $this->diasSemanaNombres = self::DIAS_SEMANA;

        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        $this->nombre_comercial = $distribuidora->nombre_comercial;
        $this->descripcion_publica = $distribuidora->descripcion_publica;
        $this->direccion_publica = $distribuidora->direccion_publica;
        $this->telefono_publico = $distribuidora->telefono_publico;
        $this->email_publico = $distribuidora->email_publico;
        $this->horario_publico = $distribuidora->horario_publico;
        $this->logotipo_url_actual = $distribuidora->logotipo_url;

        $config = ConfiguracionDistribuidora::first();
        if ($config) {
            $this->anticipo_por_producto = (float) $config->anticipo_por_producto;
            $this->dias_solicitud_cambio = $config->dias_solicitud_cambio;
            $this->dias_gestion_devolucion = $config->dias_gestion_devolucion;
            $this->dias_vigencia_vale = $config->dias_vigencia_vale;
            $this->dias_maximos_recoleccion = $config->dias_maximos_recoleccion;
            $this->moneda = $config->moneda;
            $this->zona_horaria = $config->zona_horaria;
        }

        $this->cargarCiclos();
        $this->cargarEmpleados();
        $this->cargarRevendedores();
        $this->cargarClientesDirectos();
    }

    private function cargarCiclos(): void
    {
        $this->ciclos = ConfiguracionCiclo::with('diasRecepcion')->orderByDesc('activa')->get();
    }

    private function cargarClientesDirectos(): void
    {
        $this->clientesDirectos = ClienteDirecto::all();
    }

    private function cargarEmpleados(): void
    {
        $this->empleados = DistribuidoraStaff::with('usuario')->where('tipo', 'empleado')->get();
    }

    private function cargarRevendedores(): void
    {
        $this->revendedores = RevendedorDistribuidora::with('revendedor')->get();
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
        $this->mostrandoFormularioRevendedor = true;
    }

    public function abrirFormularioEditarRevendedor(int $id): void
    {
        $afiliacion = RevendedorDistribuidora::with('revendedor')->findOrFail($id);
        $this->revendedorEditandoId = $afiliacion->id;
        $this->revendedor_nombre = $afiliacion->revendedor->nombre;
        $this->revendedor_telefono = $afiliacion->revendedor->telefono;
        $this->revendedor_email = $afiliacion->revendedor->email;
        $this->revendedor_codigo_interno = $afiliacion->codigo_interno;
        $this->revendedor_estado = $afiliacion->estado;
        $this->mostrandoFormularioRevendedor = true;
    }

    public function cancelarFormularioRevendedor(): void
    {
        $this->mostrandoFormularioRevendedor = false;
        $this->revendedorEditandoId = null;
    }

    public function guardarRevendedor(): void
    {
        $datos = $this->validate([
            'revendedor_nombre' => ['required', 'string', 'max:150'],
            'revendedor_telefono' => ['nullable', 'string', 'max:30'],
            'revendedor_email' => ['nullable', 'email', 'max:190'],
            'revendedor_codigo_interno' => ['nullable', 'string', 'max:60'],
        ]);

        $payload = [
            'nombre' => $datos['revendedor_nombre'],
            'telefono' => $datos['revendedor_telefono'],
            'email' => $datos['revendedor_email'],
            'codigo_interno' => $datos['revendedor_codigo_interno'],
            'estado' => $this->revendedor_estado,
        ];

        $accion = app(GestionarRevendedorAction::class);

        if ($this->revendedorEditandoId) {
            $accion->actualizar(RevendedorDistribuidora::findOrFail($this->revendedorEditandoId), $payload);
        } else {
            $accion->afiliar($payload);
        }

        $this->mostrandoFormularioRevendedor = false;
        $this->revendedorEditandoId = null;
        $this->cargarRevendedores();
        $this->dispatch('guardado', mensaje: 'Revendedor guardado correctamente.');
    }

    public function abrirFormularioCrearCliente(): void
    {
        $this->clienteEditandoId = null;
        $this->cliente_nombre = '';
        $this->cliente_telefono = null;
        $this->cliente_email = null;
        $this->cliente_direccion_contacto = null;
        $this->cliente_estado = 'activo';
        $this->mostrandoFormularioCliente = true;
    }

    public function abrirFormularioEditarCliente(int $id): void
    {
        $cliente = ClienteDirecto::findOrFail($id);
        $this->clienteEditandoId = $cliente->id;
        $this->cliente_nombre = $cliente->nombre;
        $this->cliente_telefono = $cliente->telefono;
        $this->cliente_email = $cliente->email;
        $this->cliente_direccion_contacto = $cliente->direccion_contacto;
        $this->cliente_estado = $cliente->estado;
        $this->mostrandoFormularioCliente = true;
    }

    public function cancelarFormularioCliente(): void
    {
        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
    }

    public function guardarCliente(): void
    {
        $datos = $this->validate([
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'cliente_email' => ['nullable', 'email', 'max:190'],
            'cliente_direccion_contacto' => ['nullable', 'string', 'max:300'],
        ]);

        $payload = [
            'nombre' => $datos['cliente_nombre'],
            'telefono' => $datos['cliente_telefono'],
            'email' => $datos['cliente_email'],
            'direccion_contacto' => $datos['cliente_direccion_contacto'],
            'estado' => $this->cliente_estado,
        ];

        $accion = app(GestionarClienteDirectoAction::class);

        if ($this->clienteEditandoId) {
            $accion->actualizar(ClienteDirecto::findOrFail($this->clienteEditandoId), $payload);
        } else {
            $accion->crear($payload);
        }

        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
        $this->cargarClientesDirectos();
        $this->dispatch('guardado', mensaje: 'Cliente directo guardado correctamente.');
    }

    public function guardarPerfil(): void
    {
        $datos = $this->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string'],
            'direccion_publica' => ['nullable', 'string', 'max:300'],
            'telefono_publico' => ['nullable', 'string', 'max:30'],
            'email_publico' => ['nullable', 'email', 'max:190'],
            'horario_publico' => ['nullable', 'string', 'max:300'],
            'logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        app(ActualizarPerfilDistribuidoraAction::class)->ejecutar(
            $distribuidora,
            collect($datos)->except('logotipo')->all(),
            $this->logotipo
        );

        $this->logotipo = null;
        $this->mount();
        $this->dispatch('guardado', mensaje: 'Perfil actualizado correctamente.');
    }

    public function guardarConfiguracion(): void
    {
        $datos = $this->validate([
            'anticipo_por_producto' => ['required', 'numeric', 'min:0'],
            'dias_solicitud_cambio' => ['required', 'integer', 'min:1'],
            'dias_gestion_devolucion' => ['required', 'integer', 'min:1'],
            'dias_vigencia_vale' => ['required', 'integer', 'min:1'],
            'dias_maximos_recoleccion' => ['required', 'integer', 'min:1'],
            'moneda' => ['required', 'string', 'size:3'],
            'zona_horaria' => ['required', 'string', 'max:60'],
        ]);

        app(ActualizarConfiguracionDistribuidoraAction::class)->ejecutar($datos);
        $this->dispatch('guardado', mensaje: 'Configuración general actualizada correctamente.');
    }

    public function abrirFormularioCrearCiclo(): void
    {
        $this->cicloEditandoId = null;
        $this->ciclo_dia_cierre = 5;
        $this->ciclo_hora_cierre = '18:00';
        $this->ciclo_dia_solicitud_fabrica = 5;
        $this->ciclo_dias_estimados_llegada = 5;
        $this->ciclo_activa = true;
        $this->ciclo_dias_recepcion = [];
        $this->mostrandoFormularioCiclo = true;
    }

    public function abrirFormularioEditarCiclo(int $id): void
    {
        $ciclo = ConfiguracionCiclo::with('diasRecepcion')->findOrFail($id);
        $this->cicloEditandoId = $ciclo->id;
        $this->ciclo_dia_cierre = $ciclo->dia_cierre;
        $this->ciclo_hora_cierre = substr($ciclo->hora_cierre, 0, 5);
        $this->ciclo_dia_solicitud_fabrica = $ciclo->dia_solicitud_fabrica;
        $this->ciclo_dias_estimados_llegada = $ciclo->dias_estimados_llegada;
        $this->ciclo_activa = (bool) $ciclo->activa;
        $this->ciclo_dias_recepcion = $ciclo->diasRecepcion->pluck('dia_semana')->all();
        $this->mostrandoFormularioCiclo = true;
    }

    public function cancelarFormularioCiclo(): void
    {
        $this->mostrandoFormularioCiclo = false;
        $this->cicloEditandoId = null;
    }

    public function guardarCiclo(): void
    {
        $datos = $this->validate([
            'ciclo_dia_cierre' => ['required', 'integer', 'between:1,7'],
            'ciclo_hora_cierre' => ['required', 'date_format:H:i'],
            'ciclo_dia_solicitud_fabrica' => ['required', 'integer', 'between:1,7'],
            'ciclo_dias_estimados_llegada' => ['required', 'integer', 'min:1'],
            'ciclo_dias_recepcion' => ['required', 'array', 'min:1'],
            'ciclo_dias_recepcion.*' => ['integer', 'between:1,7'],
        ]);

        $payload = [
            'dia_cierre' => $datos['ciclo_dia_cierre'],
            'hora_cierre' => $datos['ciclo_hora_cierre'],
            'dia_solicitud_fabrica' => $datos['ciclo_dia_solicitud_fabrica'],
            'dias_estimados_llegada' => $datos['ciclo_dias_estimados_llegada'],
            'activa' => $this->ciclo_activa,
            'dias_recepcion' => $datos['ciclo_dias_recepcion'],
        ];

        $accion = app(ConfiguracionCicloAction::class);

        if ($this->cicloEditandoId) {
            $accion->actualizar(ConfiguracionCiclo::findOrFail($this->cicloEditandoId), $payload);
        } else {
            $accion->crear($payload);
        }

        $this->mostrandoFormularioCiclo = false;
        $this->cicloEditandoId = null;
        $this->cargarCiclos();
        $this->dispatch('guardado', mensaje: 'Configuración de ciclo guardada correctamente.');
    }
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Configuración de la Distribuidora</h1>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm" style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    <div class="border-b border-slate-200 mb-6 flex gap-6 flex-wrap">
        <button type="button" wire:click="$set('pestanaActiva', 'perfil')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'perfil' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Datos Generales
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'general')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'general' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Anticipos y Plazos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'ciclos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'ciclos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Ciclos de Compra
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'usuarios')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'usuarios' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Usuarios y Revendedores
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'clientes')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'clientes' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Clientes Directos
        </button>
    </div>

    {{-- Perfil --}}
    <div x-show="$wire.pestanaActiva === 'perfil'">
        <form wire:submit="guardarPerfil" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre Comercial</label>
                <input type="text" wire:model="nombre_comercial" class="w-full rounded-md border-slate-300">
                @error('nombre_comercial') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                <textarea wire:model="descripcion_publica" rows="3" class="w-full rounded-md border-slate-300"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
                <input type="text" wire:model="direccion_publica" class="w-full rounded-md border-slate-300">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                    <input type="text" wire:model="telefono_publico" placeholder="+52 55 1234 5678" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                    <input type="email" wire:model="email_publico" class="w-full rounded-md border-slate-300">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Horario</label>
                <input type="text" wire:model="horario_publico" placeholder="Lunes a sábado, 9:00 a 19:00" class="w-full rounded-md border-slate-300">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Logotipo</label>
                @if ($logotipo)
                    <img src="{{ $logotipo->temporaryUrl() }}" class="h-16 w-16 object-cover rounded mb-2">
                @elseif ($logotipo_url_actual)
                    <img src="{{ $logotipo_url_actual }}" class="h-16 w-16 object-cover rounded mb-2">
                @endif
                <input type="file" wire:model="logotipo" accept="image/png,image/jpeg">
                <p class="text-xs text-fp-text-muted mt-1">PNG o JPG, hasta 2MB.</p>
            </div>
            <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar Cambios</button>
        </form>
    </div>

    {{-- Anticipos y plazos --}}
    <div x-show="$wire.pestanaActiva === 'general'">
        <form wire:submit="guardarConfiguracion" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Anticipo por producto (MXN)</label>
                    <input type="number" step="0.01" wire:model="anticipo_por_producto" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Días máximos de recolección</label>
                    <input type="number" wire:model="dias_maximos_recoleccion" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Días para solicitar cambio</label>
                    <input type="number" wire:model="dias_solicitud_cambio" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Días de gestión de devolución</label>
                    <input type="number" wire:model="dias_gestion_devolucion" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Días de vigencia del vale</label>
                    <input type="number" wire:model="dias_vigencia_vale" class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Moneda</label>
                    <input type="text" wire:model="moneda" maxlength="3" class="w-full rounded-md border-slate-300 uppercase">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Zona horaria</label>
                <input type="text" wire:model="zona_horaria" class="w-full rounded-md border-slate-300">
            </div>
            <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar Cambios</button>
        </form>
    </div>

    {{-- Ciclos --}}
    <div x-show="$wire.pestanaActiva === 'ciclos'">
        @if (! $mostrandoFormularioCiclo)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Configuraciones de ciclo</h2>
                    <button type="button" wire:click="abrirFormularioCrearCiclo" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva configuración</button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Cierre</th>
                            <th class="py-2">Solicitud a fábrica</th>
                            <th class="py-2">Días de llegada</th>
                            <th class="py-2">Recepción</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ciclos as $ciclo)
                            <tr class="border-b last:border-0">
                                <td class="py-2">{{ $diasSemanaNombres[$ciclo->dia_cierre] }}, {{ substr($ciclo->hora_cierre, 0, 5) }}</td>
                                <td class="py-2">{{ $diasSemanaNombres[$ciclo->dia_solicitud_fabrica] }}</td>
                                <td class="py-2">{{ $ciclo->dias_estimados_llegada }} días</td>
                                <td class="py-2">{{ $ciclo->diasRecepcion->pluck('dia_semana')->map(fn ($d) => $diasSemanaNombres[$d])->implode(', ') }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $ciclo->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $ciclo->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="abrirFormularioEditarCiclo({{ $ciclo->id }})" class="text-fp-primary text-xs font-medium">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarCiclo" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">{{ $cicloEditandoId ? 'Editar configuración de ciclo' : 'Nueva configuración de ciclo' }}</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Día de cierre</label>
                        <select wire:model="ciclo_dia_cierre" class="w-full rounded-md border-slate-300">
                            @foreach ($diasSemanaNombres as $numero => $nombre)
                                <option value="{{ $numero }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Hora de cierre</label>
                        <input type="time" wire:model="ciclo_hora_cierre" class="w-full rounded-md border-slate-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Día de solicitud a fábrica</label>
                        <select wire:model="ciclo_dia_solicitud_fabrica" class="w-full rounded-md border-slate-300">
                            @foreach ($diasSemanaNombres as $numero => $nombre)
                                <option value="{{ $numero }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Días estimados de llegada</label>
                        <input type="number" wire:model="ciclo_dias_estimados_llegada" class="w-full rounded-md border-slate-300">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Días de recepción</label>
                    <div class="flex gap-3 flex-wrap">
                        @foreach ($diasSemanaNombres as $numero => $nombre)
                            <label class="flex items-center gap-1.5 text-sm">
                                <input type="checkbox" wire:model="ciclo_dias_recepcion" value="{{ $numero }}" class="rounded border-slate-300">
                                {{ $nombre }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="ciclo_activa" class="rounded border-slate-300">
                    Marcar como configuración activa
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioCiclo" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Usuarios y Revendedores --}}
    <div x-show="$wire.pestanaActiva === 'usuarios'" class="space-y-6">

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
                        <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
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
                        <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                        <button type="button" wire:click="cancelarFormularioRevendedor" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Clientes --}}
    <div x-show="$wire.pestanaActiva === 'clientes'">
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
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioCliente" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
</div>