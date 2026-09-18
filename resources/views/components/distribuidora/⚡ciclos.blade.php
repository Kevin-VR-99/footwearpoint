<?php

use App\Models\ConfiguracionCiclo;
use App\Services\Distribuidora\ConfiguracionCicloAction;
use Livewire\Component;

new class extends Component {
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

    public function mount(): void
    {
        $this->diasSemanaNombres = self::DIAS_SEMANA;
        $this->cargarCiclos();
    }

    private function cargarCiclos(): void
    {
        $this->ciclos = ConfiguracionCiclo::with('diasRecepcion')->orderByDesc('activa')->get();
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
                <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCiclo" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
