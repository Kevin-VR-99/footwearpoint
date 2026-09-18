<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Suscripcion;
use App\Services\Catalogo\GestionarLineaAction;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public ?string $errorNegocio = null;

    public $lineas = [];
    public ?int $lineaEditandoId = null;
    public bool $mostrandoFormularioLinea = false;
    public ?int $linea_campana_id = null;
    public string $linea_nombre = '';
    public ?string $linea_descripcion = null;
    public bool $linea_activa = true;
    public array $linea_marca_ids = [];

    /** Cupo del plan (lineas activas / limite contratado). */
    public int $lineasActivasCount = 0;
    public ?int $lineasLimitePlan = null;
    public bool $cupoLineasAlcanzado = false;

    public $campanas = [];
    public $marcas = [];

    public function mount(): void
    {
        $this->cargarLineas();
        $this->cargarCupoLineas();
        $this->cargarCampanas();
        $this->cargarMarcas();
    }

    private function cargarLineas(): void
    {
        $this->lineas = Linea::with(['campana', 'marcas'])
            ->latest()
            ->get();
    }

    private function cargarCupoLineas(): void
    {
        $this->lineasActivasCount = (int) Linea::where('activa', true)->count();

        $suscripcion = Suscripcion::where('estado', 'activa')->first();

        if (! $suscripcion) {
            $this->lineasLimitePlan = null;
            $this->cupoLineasAlcanzado = true;

            return;
        }

        $this->lineasLimitePlan = (int) $suscripcion->lineas_incluidas_contratadas
            + (int) $suscripcion->lineas_extra_contratadas;
        $this->cupoLineasAlcanzado = $this->lineasActivasCount >= $this->lineasLimitePlan;
    }

    private function cargarCampanas(): void
    {
        $this->campanas = Campana::with('lineas')->latest()->get();
    }

    private function cargarMarcas(): void
    {
        $this->marcas = Marca::all();
    }

    public function abrirFormularioCrearLinea(): void
    {
        $this->cargarCupoLineas();

        if ($this->cupoLineasAlcanzado) {
            $limite = $this->lineasLimitePlan;
            $this->errorNegocio = $limite === null
                ? 'No hay una suscripción activa; no se pueden crear líneas.'
                : "Ya alcanzaste el límite de {$limite} línea(s) activa(s) de tu plan actual. Contacta al administrador general para ampliar tu plan.";

            return;
        }

        $this->errorNegocio = null;
        $this->lineaEditandoId = null;
        $this->linea_campana_id = null;
        $this->linea_nombre = '';
        $this->linea_descripcion = null;
        $this->linea_activa = true;
        $this->linea_marca_ids = [];
        $this->mostrandoFormularioLinea = true;
    }

    public function abrirFormularioEditarLinea(int $id): void
    {
        $this->errorNegocio = null;
        $linea = Linea::with('marcas')->findOrFail($id);
        $this->lineaEditandoId = $linea->id;
        $this->linea_campana_id = $linea->campana_id;
        $this->linea_nombre = $linea->nombre;
        $this->linea_descripcion = $linea->descripcion;
        $this->linea_activa = (bool) $linea->activa;
        $this->linea_marca_ids = $linea->marcas->pluck('id')->map(fn($id) => (string) $id)->all();
        $this->mostrandoFormularioLinea = true;
    }

    public function cancelarFormularioLinea(): void
    {
        $this->mostrandoFormularioLinea = false;
        $this->lineaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarLinea(): void
    {
        $this->errorNegocio = null;
        $esCreacion = !$this->lineaEditandoId;
        $reglas = [
            'linea_nombre' => ['required', 'string', 'max:150'],
            'linea_descripcion' => ['nullable', 'string'],
            'linea_marca_ids' => ['sometimes', 'array'],
            'linea_marca_ids.*' => ['integer', Rule::exists('marcas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))],
        ];
        if ($esCreacion) {
            $reglas['linea_campana_id'] = ['required', 'integer', Rule::exists('campanas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))];
        }
        $datos = $this->validate($reglas);
        $accion = app(GestionarLineaAction::class);
        $marcaIds = array_map('intval', $this->linea_marca_ids ?? []);
        try {
            if ($esCreacion) {
                $accion->crear(
                    [
                        'campana_id' => $datos['linea_campana_id'],
                        'nombre' => $datos['linea_nombre'],
                        'descripcion' => $datos['linea_descripcion'] ?? null,
                    ],
                    $marcaIds,
                );
            } else {
                $accion->actualizar(
                    Linea::findOrFail($this->lineaEditandoId),
                    [
                        'nombre' => $datos['linea_nombre'],
                        'descripcion' => $datos['linea_descripcion'] ?? null,
                        'activa' => $this->linea_activa,
                    ],
                    $marcaIds,
                );
            }
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }
        $this->mostrandoFormularioLinea = false;
        $this->lineaEditandoId = null;
        $this->cargarLineas();
        $this->cargarCupoLineas();
        $this->dispatch('guardado', mensaje: 'Línea guardada correctamente.');
    }

};
?>

<div>
    @if ($errorNegocio)
        <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
            {{ $errorNegocio }}</div>
    @endif
    @if (!$mostrandoFormularioLinea)
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Líneas comerciales</h2>
                <div class="flex items-center gap-3">
                    @if ($lineasLimitePlan === null)
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">
                            Sin plan activo
                        </span>
                    @else
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $cupoLineasAlcanzado ? 'bg-fp-badge-danger-bg text-fp-badge-danger-fg ring-red-600/20' : 'bg-slate-50 text-slate-700 ring-slate-500/20' }}"
                            title="Líneas activas que cuentan para el cupo del plan">
                            Cupo: {{ $lineasActivasCount }} / {{ $lineasLimitePlan }}
                        </span>
                    @endif
                    <button type="button" wire:click="abrirFormularioCrearLinea"
                        @disabled($cupoLineasAlcanzado)
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">+ Nueva
                        línea</button>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Temporada</th>
                        <th class="py-2">Marcas</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lineas as $linea)
                        <tr class="border-b last:border-0">
                            <td class="py-2 font-medium">{{ $linea->nombre }}</td>
                            <td class="py-2">{{ $linea->campana?->nombre ?? '—' }}</td>
                            <td class="py-2">{{ $linea->marcas->pluck('nombre')->join(', ') ?: '—' }}</td>
                            <td class="py-2">{{ $linea->activa ? 'Activa' : 'Inactiva' }}</td>
                            <td class="py-2 text-right">
                                <button type="button"
                                    wire:click="abrirFormularioEditarLinea({{ $linea->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-500">No hay líneas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarLinea" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">
                {{ $lineaEditandoId ? 'Editar línea' : 'Nueva línea' }}</h2>
            @if (!$lineaEditandoId)
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Temporada</label>
                    <select wire:model="linea_campana_id" class="w-full rounded-md border-slate-300">
                        <option value="">Seleccionar temporada</option>
                        @foreach ($campanas as $campana)
                            <option value="{{ $campana->id }}">{{ $campana->nombre }}</option>
                        @endforeach
                    </select>
                    @error('linea_campana_id')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
            @endif
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                <input type="text" wire:model="linea_nombre" class="w-full rounded-md border-slate-300">
                @error('linea_nombre')
                    <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                <textarea wire:model="linea_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Marcas asociadas</label>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto border rounded-md p-3">
                    @foreach ($marcas as $marca)
                        @if ($marca->activa)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="linea_marca_ids" value="{{ $marca->id }}"
                                    class="rounded border-slate-300">
                                {{ $marca->nombre }}
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
            @if ($lineaEditandoId)
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="linea_activa" class="rounded border-slate-300">
                    Línea activa (cuenta para el cupo del plan)
                </label>
            @endif
            <div class="flex gap-2">
                <button type="submit"
                    class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar
                    línea</button>
                <button type="button" wire:click="cancelarFormularioLinea"
                    class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
