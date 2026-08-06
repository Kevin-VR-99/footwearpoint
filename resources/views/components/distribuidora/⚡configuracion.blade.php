<?php

use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::distribuidora')] class extends Component {
    use WithFileUploads;

    // --- Estado de la pestaña ---
    public string $pestanaActiva = 'perfil';

    // --- Perfil (E3-01) ---
    public string $nombre_comercial = '';
    public ?string $descripcion_publica = null;
    public ?string $direccion_publica = null;
    public ?string $telefono_publico = null;
    public ?string $email_publico = null;
    public ?string $horario_publico = null;
    public $logotipo = null; // archivo nuevo, si el usuario sube uno
    public ?string $logotipo_url_actual = null;

    // --- Configuración general (E3-06) ---
    public float $anticipo_por_producto = 0;
    public int $dias_solicitud_cambio = 12;
    public int $dias_gestion_devolucion = 20;
    public int $dias_vigencia_vale = 90;
    public int $dias_maximos_recoleccion = 5;
    public string $moneda = 'MXN';
    public string $zona_horaria = 'America/Mexico_City';

    /**
     * Se cargan los datos actuales al abrir la pantalla — igual que el
     * GET que ya probamos por curl, pero aquí se llama directo al modelo
     * en vez de por HTTP (estamos dentro del mismo proceso de Laravel).
     */
    public function mount(): void
    {
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
    }

    /**
     * Mismas reglas que ActualizarPerfilDistribuidoraRequest (Bloque 1) —
     * se repiten aquí porque Livewire valida en su propio ciclo, no
     * reutiliza el Form Request de la API directamente.
     */
    public function guardarPerfil(): void
    {
        $datos = $this->validate([
            'nombre_comercial'    => ['required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string'],
            'direccion_publica'   => ['nullable', 'string', 'max:300'],
            'telefono_publico'    => ['nullable', 'string', 'max:30'],
            'email_publico'       => ['nullable', 'email', 'max:190'],
            'horario_publico'     => ['nullable', 'string', 'max:300'],
            'logotipo'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());

        app(ActualizarPerfilDistribuidoraAction::class)->ejecutar(
            $distribuidora,
            collect($datos)->except('logotipo')->all(),
            $this->logotipo
        );

        $this->logotipo = null;
        $this->mount(); // recarga los datos (incluida la nueva URL del logo)

        $this->dispatch('guardado', mensaje: 'Perfil actualizado correctamente.');
    }

    public function guardarConfiguracion(): void
    {
        $datos = $this->validate([
            'anticipo_por_producto'    => ['required', 'numeric', 'min:0'],
            'dias_solicitud_cambio'    => ['required', 'integer', 'min:1'],
            'dias_gestion_devolucion'  => ['required', 'integer', 'min:1'],
            'dias_vigencia_vale'       => ['required', 'integer', 'min:1'],
            'dias_maximos_recoleccion' => ['required', 'integer', 'min:1'],
            'moneda'                   => ['required', 'string', 'size:3'],
            'zona_horaria'             => ['required', 'string', 'max:60'],
        ]);

        app(ActualizarConfiguracionDistribuidoraAction::class)->ejecutar($datos);

        $this->dispatch('guardado', mensaje: 'Configuración general actualizada correctamente.');
    }
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Configuración de la Distribuidora</h1>

    {{-- Aviso de guardado exitoso (escucha el evento 'guardado' de arriba) --}}
    <div
        x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible"
        x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm"
        style="display: none;"
    >
        <span x-text="mensaje"></span>
    </div>

    {{-- Pestañas --}}
    <div class="border-b border-slate-200 mb-6 flex gap-6">
        <button
            type="button"
            wire:click="$set('pestanaActiva', 'perfil')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'perfil' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}"
        >
            Datos Generales
        </button>
        <button
            type="button"
            wire:click="$set('pestanaActiva', 'general')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'general' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}"
        >
            Anticipos y Plazos
        </button>
    </div>

    {{-- Pestaña: Perfil --}}
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
                    {{-- Corrección de mockup (Correcciones_Mockups.docx): formato México, no España --}}
                    <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                    <input type="text" wire:model="telefono_publico" placeholder="+52 55 1234 5678" class="w-full rounded-md border-slate-300">
                    @error('telefono_publico') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                    <input type="email" wire:model="email_publico" class="w-full rounded-md border-slate-300">
                    @error('email_publico') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
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
                @error('logotipo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                <div wire:loading wire:target="logotipo" class="text-xs text-fp-text-muted">Subiendo...</div>
            </div>

            <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarPerfil">
                Guardar Cambios
            </button>
        </form>
    </div>

    {{-- Pestaña: Configuración general --}}
    <div x-show="$wire.pestanaActiva === 'general'">
        <form wire:submit="guardarConfiguracion" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Anticipo por producto (MXN)</label>
                    <input type="number" step="0.01" wire:model="anticipo_por_producto" class="w-full rounded-md border-slate-300">
                    @error('anticipo_por_producto') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Días máximos de recolección</label>
                    <input type="number" wire:model="dias_maximos_recoleccion" class="w-full rounded-md border-slate-300">
                    @error('dias_maximos_recoleccion') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
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
                    @error('moneda') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Zona horaria</label>
                <input type="text" wire:model="zona_horaria" class="w-full rounded-md border-slate-300">
            </div>

            <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarConfiguracion">
                Guardar Cambios
            </button>
        </form>
    </div>
</div>
