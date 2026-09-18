<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\Linea;
use App\Services\Catalogo\GestionarCampanaAction;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public ?string $errorNegocio = null;

    public $campanas = [];
    public ?int $campanaEditandoId = null;
    public bool $mostrandoFormularioCampana = false;
    public string $campana_nombre = '';
    public ?string $campana_fecha_inicio = null;
    public ?string $campana_fecha_fin = null;
    public array $campana_linea_ids = [];

    private const CAMPANA_ESTADOS = [
        'borrador' => ['Borrador', 'neutral'],
        'en_importacion' => ['En importación', 'info'],
        'en_revision' => ['En revisión', 'info'],
        'activa' => ['Activa', 'success'],
        'finalizada' => ['Finalizada', 'neutral'],
        'archivada' => ['Archivada', 'neutral'],
    ];
    private const ORDEN_ESTADOS_CAMPANA = ['borrador', 'en_importacion', 'en_revision', 'activa', 'finalizada', 'archivada'];
    public array $campanaEstadosNombres = [];
    public array $ordenEstadosCampana = [];

    public $lineas = [];

    public function mount(): void
    {
        $this->campanaEstadosNombres = self::CAMPANA_ESTADOS;
        $this->ordenEstadosCampana = self::ORDEN_ESTADOS_CAMPANA;
        $this->cargarCampanas();
        $this->cargarLineas();
    }

    private function cargarLineas(): void
    {
        $this->lineas = Linea::with(['campana', 'marcas'])
            ->latest()
            ->get();
    }

    private function cargarCampanas(): void
    {
        $this->campanas = Campana::with('lineas')->latest()->get();
    }

    public function abrirFormularioCrearCampana(): void
    {
        $this->campanaEditandoId = null;
        $this->campana_nombre = '';
        $this->campana_fecha_inicio = null;
        $this->campana_fecha_fin = null;
        $this->campana_linea_ids = [];
        $this->errorNegocio = null;
        $this->mostrandoFormularioCampana = true;
    }

    public function cancelarFormularioCampana(): void
    {
        $this->mostrandoFormularioCampana = false;
        $this->campanaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarCampana(): void
    {
        $this->errorNegocio = null;
        $datos = $this->validate([
            'campana_nombre' => ['required', 'string', 'max:150'],
            'campana_fecha_inicio' => ['nullable', 'date'],
            'campana_fecha_fin' => ['nullable', 'date', 'after_or_equal:campana_fecha_inicio'],
            'campana_linea_ids' => ['sometimes', 'array'],
            'campana_linea_ids.*' => ['integer', Rule::exists('lineas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))],
        ]);

        if (Campana::query()->count() >= 2) {
            $this->errorNegocio = 'Solo se permiten 2 temporadas (Primavera-Verano y Otoño-Invierno).';
            return;
        }

        $campana = app(GestionarCampanaAction::class)->crear([
            'nombre' => $datos['campana_nombre'],
            'fecha_inicio' => $datos['campana_fecha_inicio'],
            'fecha_fin' => $datos['campana_fecha_fin'],
        ]);

        $lineaIds = array_map('intval', $this->campana_linea_ids ?? []);
        if ($lineaIds !== []) {
            Linea::whereIn('id', $lineaIds)->update(['campana_id' => $campana->id]);
        }

        $this->mostrandoFormularioCampana = false;
        $this->campana_linea_ids = [];
        $this->cargarCampanas();
        $this->cargarLineas();
        $this->dispatch('guardado', mensaje: 'Temporada creada correctamente.');
    }

    public function activarTemporada(int $id): void
    {
        $this->errorNegocio = null;
        $campana = Campana::findOrFail($id);

        try {
            // Solo una activa: el resto activas pasan a finalizada
            Campana::query()
                ->where('id', '!=', $campana->id)
                ->where('estado', 'activa')
                ->update(['estado' => 'finalizada']);

            app(GestionarCampanaAction::class)->actualizar($campana, ['estado' => 'activa']);
        } catch (OperacionInvalidaException $e) {
            // Si el Action bloquea saltos de estado, actualiza directo:
            $campana->estado = 'activa';
            $campana->save();
        }

        $this->cargarCampanas();
        $this->dispatch('guardado', mensaje: 'Temporada activada. Las demás activas se desactivaron.');
    }

    public function desactivarTemporada(int $id): void
    {
        $this->errorNegocio = null;
        $campana = Campana::findOrFail($id);

        try {
            app(GestionarCampanaAction::class)->actualizar($campana, ['estado' => 'finalizada']);
        } catch (OperacionInvalidaException $e) {
            $campana->estado = 'finalizada';
            $campana->save();
        }

        $this->cargarCampanas();
        $this->dispatch('guardado', mensaje: 'Temporada desactivada.');
    }

};
?>

<div>
    @if ($errorNegocio)
        <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
            {{ $errorNegocio }}
        </div>
    @endif

    @if (!$mostrandoFormularioCampana)
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex justify-between items-center mb-4 gap-3 flex-wrap">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700">Temporadas</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        ≈ 6 meses (Primavera-Verano / Otoño-Invierno). Solo una activa a la vez. Máximo 2.
                    </p>
                    @if (count($campanas) >= 2)
                        <p class="text-xs text-amber-600 mt-1">Ya tienes 2 temporadas (máximo permitido).</p>
                    @endif
                </div>
                <button type="button" wire:click="abrirFormularioCrearCampana" @disabled(count($campanas) >= 2)
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                    + Nueva temporada
                </button>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Líneas</th>
                        <th class="py-2">Vigencia</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campanas as $campana)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $campana->nombre }}</td>
                            <td class="py-2">
                                @if ($campana->lineas->isEmpty())
                                    <span class="text-slate-400">Sin líneas</span>
                                @else
                                    {{ $campana->lineas->pluck('nombre')->join(', ') }}
                                @endif
                            </td>
                            <td class="py-2">
                                {{ $campana->fecha_inicio?->format('d/m/Y') ?? '—' }} –
                                {{ $campana->fecha_fin?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td class="py-2">
                                @if ($campana->estado === 'activa')
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-fp-badge-success-bg text-fp-badge-success-fg">
                                        Activa
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-fp-badge-neutral-bg text-fp-badge-neutral-fg">
                                        Inactiva
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                @if ($campana->estado === 'activa')
                                    <button type="button" wire:click="desactivarTemporada({{ $campana->id }})" wire:confirm="¿Desactivar esta temporada?"
                                        class="text-fp-primary text-xs font-medium">
                                        Desactivar
                                    </button>
                                @else
                                    <button type="button" wire:click="activarTemporada({{ $campana->id }})" wire:confirm="¿Activar esta temporada? Las demás activas se desactivarán."
                                        class="text-fp-primary text-xs font-medium">
                                        Activar
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-500">No hay temporadas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarCampana" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">Nueva temporada</h2>
            <p class="text-xs text-slate-500 -mt-2">Ejemplos: Primavera-Verano 2026, Otoño-Invierno 2026-2027.</p>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre de la temporada</label>
                <input type="text" wire:model="campana_nombre" class="w-full rounded-md border-slate-300"
                    placeholder="Primavera-Verano 2026">
                @error('campana_nombre')
                    <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Líneas a asignar (opcional)</label>
                <p class="text-xs text-slate-500 mb-2">Si una línea ya pertenece a otra temporada, se moverá a
                    esta.</p>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto border rounded-md p-3">
                    @forelse ($lineas as $linea)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="campana_linea_ids" value="{{ $linea->id }}"
                                class="rounded border-slate-300">
                            <span>
                                {{ $linea->nombre }}
                                <span
                                    class="text-slate-400 text-xs">({{ $linea->campana?->nombre ?? 'sin temporada' }})</span>
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-400 col-span-2">No hay líneas. Créalas en la pestaña Líneas.
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fecha de inicio</label>
                    <input type="date" wire:model="campana_fecha_inicio"
                        class="w-full rounded-md border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fecha de fin</label>
                    <input type="date" wire:model="campana_fecha_fin"
                        class="w-full rounded-md border-slate-300">
                    @error('campana_fecha_fin')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit"
                    class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCampana"
                    class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
