<?php

use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Services\Distribuidora\ConfiguracionCicloAction;
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

    // --- Ciclos de compra (E3-05) — sin mockup, pantalla armada con tokens ---
    public $ciclos = [];
    public ?int $cicloEditandoId = null; // null = modo "crear"
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

    /**
     * Se cargan los datos actuales al abrir la pantalla — igual que el
     * GET que ya probamos por curl, pero aquí se llama directo al modelo
     * en vez de por HTTP (estamos dentro del mismo proceso de Laravel).
     */
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
    }

    private function cargarCiclos(): void
    {
        $this->ciclos = ConfiguracionCiclo::with('diasRecepcion')->orderByDesc('activa')->get();
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
        $this->ciclo_hora_cierre = substr($ciclo->hora_cierre, 0, 5); // "18:00:00" -> "18:00"
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

    /**
     * Mismas reglas que GuardarConfiguracionCicloRequest (Bloque 1).
     */
    public function guardarCiclo(): void
    {
        $datos = $this->validate([
            'ciclo_dia_cierre'             => ['required', 'integer', 'between:1,7'],
            'ciclo_hora_cierre'            => ['required', 'date_format:H:i'],
            'ciclo_dia_solicitud_fabrica'  => ['required', 'integer', 'between:1,7'],
            'ciclo_dias_estimados_llegada' => ['required', 'integer', 'min:1'],
            'ciclo_dias_recepcion'         => ['required', 'array', 'min:1'],
            'ciclo_dias_recepcion.*'       => ['integer', 'between:1,7'],
        ]);

        $payload = [
            'dia_cierre'             => $datos['ciclo_dia_cierre'],
            'hora_cierre'            => $datos['ciclo_hora_cierre'],
            'dia_solicitud_fabrica'  => $datos['ciclo_dia_solicitud_fabrica'],
            'dias_estimados_llegada' => $datos['ciclo_dias_estimados_llegada'],
            'activa'                 => $this->ciclo_activa,
            'dias_recepcion'         => $datos['ciclo_dias_recepcion'],
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
        <button
            type="button"
            wire:click="$set('pestanaActiva', 'ciclos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'ciclos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}"
        >
            Ciclos de Compra
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

    {{-- Pestaña: Ciclos de compra --}}
    <div x-show="$wire.pestanaActiva === 'ciclos'">
        @if (! $mostrandoFormularioCiclo)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Configuraciones de ciclo</h2>
                    <button type="button" wire:click="abrirFormularioCrearCiclo" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Nueva configuración
                    </button>
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
                                    <button type="button" wire:click="abrirFormularioEditarCiclo({{ $ciclo->id }})" class="text-fp-primary text-xs font-medium">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarCiclo" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $cicloEditandoId ? 'Editar configuración de ciclo' : 'Nueva configuración de ciclo' }}
                </h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Día de cierre</label>
                        <select wire:model="ciclo_dia_cierre" class="w-full rounded-md border-slate-300">
                            @foreach ($diasSemanaNombres as $numero => $nombre)
                                <option value="{{ $numero }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                        @error('ciclo_dia_cierre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Hora de cierre</label>
                        <input type="time" wire:model="ciclo_hora_cierre" class="w-full rounded-md border-slate-300">
                        @error('ciclo_hora_cierre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
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
                        @error('ciclo_dias_estimados_llegada') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
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
                    @error('ciclo_dias_recepcion') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="ciclo_activa" class="rounded border-slate-300">
                        Marcar como configuración activa
                    </label>
                    <p class="text-xs text-fp-text-muted mt-1">
                        Solo puede haber una configuración activa a la vez — al marcar esta, la anterior se desactiva sola.
                    </p>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarCiclo">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelarFormularioCiclo" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
