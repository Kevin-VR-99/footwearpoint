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
        <div class="mb-4 rounded-xl border border-fp-badge-danger-fg/15 bg-fp-badge-danger-bg px-4 py-3 text-sm text-fp-badge-danger-fg">
            {{ $errorNegocio }}</div>
    @endif
    @if (!$mostrandoFormularioLinea)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-slate-900">Líneas comerciales</h2>
                    <p class="mt-0.5 text-sm text-fp-text-muted">Líneas asociadas a temporadas y marcas.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($lineasLimitePlan === null)
                        <span class="inline-flex items-center rounded-full bg-fp-badge-warning-bg px-2.5 py-1 text-xs font-medium text-fp-badge-warning-fg ring-1 ring-inset ring-amber-600/15">
                            Sin plan activo
                        </span>
                    @else
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $cupoLineasAlcanzado ? 'bg-fp-badge-danger-bg text-fp-badge-danger-fg ring-red-600/15' : 'bg-fp-page text-slate-700 ring-slate-500/15' }}"
                            title="Líneas activas que cuentan para el cupo del plan">
                            Cupo: {{ $lineasActivasCount }} / {{ $lineasLimitePlan }}
                        </span>
                    @endif
                    <button type="button" wire:click="abrirFormularioCrearLinea"
                        @disabled($cupoLineasAlcanzado)
                        class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nueva línea
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-fp-page/80 text-left text-[11px] font-semibold uppercase tracking-wide text-fp-text-muted">
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Temporada</th>
                            <th class="px-6 py-3">Marcas</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($lineas as $linea)
                            <tr class="transition-colors hover:bg-fp-page/70">
                                <td class="px-6 py-3.5 font-medium text-slate-800">{{ $linea->nombre }}</td>
                                <td class="px-6 py-3.5 text-slate-600">{{ $linea->campana?->nombre ?? '—' }}</td>
                                <td class="px-6 py-3.5 text-slate-600">{{ $linea->marcas->pluck('nombre')->join(', ') ?: '—' }}</td>
                                <td class="px-6 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $linea->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $linea->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <button type="button"
                                        wire:click="abrirFormularioEditarLinea({{ $linea->id }})"
                                        class="text-sm font-medium text-fp-primary hover:underline">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-14 text-center">
                                    <p class="text-sm font-medium text-fp-sidebar">No hay líneas</p>
                                    <p class="mt-1 text-sm text-fp-text-muted">Crea la primera para organizar tu temporada.</p>
                                    @unless ($cupoLineasAlcanzado)
                                        <button type="button" wire:click="abrirFormularioCrearLinea"
                                            class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                            Nueva línea
                                        </button>
                                    @endunless
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form wire:submit="guardarLinea" class="mx-auto max-w-2xl space-y-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    {{ $lineaEditandoId ? 'Editar línea' : 'Nueva línea' }}</h2>
                <p class="mt-0.5 text-sm text-fp-text-muted">
                    {{ $lineaEditandoId ? 'Actualiza la línea y sus marcas asociadas.' : 'Asocia la línea a una temporada y marcas.' }}
                </p>
            </div>
            @if (!$lineaEditandoId)
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Temporada</label>
                    <select wire:model="linea_campana_id"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                        <option value="">Seleccionar temporada</option>
                        @foreach ($campanas as $campana)
                            <option value="{{ $campana->id }}">{{ $campana->nombre }}</option>
                        @endforeach
                    </select>
                    @error('linea_campana_id')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
            @endif
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Nombre</label>
                <input type="text" wire:model="linea_nombre"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                @error('linea_nombre')
                    <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Descripción</label>
                <textarea wire:model="linea_descripcion" rows="2"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"></textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Marcas asociadas</label>
                <div class="grid max-h-48 grid-cols-2 gap-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                    @foreach ($marcas as $marca)
                        @if ($marca->activa)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="linea_marca_ids" value="{{ $marca->id }}"
                                    class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                                {{ $marca->nombre }}
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
            @if ($lineaEditandoId)
                <label class="flex items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" wire:model="linea_activa"
                        class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                    Línea activa (cuenta para el cupo del plan)
                </label>
            @endif
            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled">Guardar línea</button>
                <button type="button" wire:click="cancelarFormularioLinea"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Cancelar</button>
            </div>
        </form>
    @endif
</div>
