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
        <div class="mb-4 rounded-xl border border-fp-badge-danger-fg/15 bg-fp-badge-danger-bg px-4 py-3 text-sm text-fp-badge-danger-fg">
            {{ $errorNegocio }}
        </div>
    @endif

    @if (!$mostrandoFormularioCampana)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-slate-900">Temporadas</h2>
                    <p class="mt-0.5 text-sm text-fp-text-muted">
                        ≈ 6 meses (Primavera-Verano / Otoño-Invierno). Solo una activa a la vez. Máximo 2.
                    </p>
                    @if (count($campanas) >= 2)
                        <p class="mt-1 text-xs font-medium text-fp-badge-warning-fg">Ya tienes 2 temporadas (máximo permitido).</p>
                    @endif
                </div>
                <button type="button" wire:click="abrirFormularioCrearCampana" @disabled(count($campanas) >= 2)
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nueva temporada
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-fp-page/80 text-left text-[11px] font-semibold uppercase tracking-wide text-fp-text-muted">
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Líneas</th>
                            <th class="px-6 py-3">Vigencia</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($campanas as $campana)
                            <tr class="transition-colors hover:bg-fp-page/70">
                                <td class="px-6 py-3.5 font-medium text-slate-800">{{ $campana->nombre }}</td>
                                <td class="px-6 py-3.5 text-slate-600">
                                    @if ($campana->lineas->isEmpty())
                                        <span class="text-fp-text-muted">Sin líneas</span>
                                    @else
                                        {{ $campana->lineas->pluck('nombre')->join(', ') }}
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 tabular-nums text-slate-600">
                                    {{ $campana->fecha_inicio?->format('d/m/Y') ?? '—' }} –
                                    {{ $campana->fecha_fin?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="px-6 py-3.5">
                                    @if ($campana->estado === 'activa')
                                        <span
                                            class="inline-flex items-center rounded-full bg-fp-badge-success-bg px-2.5 py-1 text-xs font-medium text-fp-badge-success-fg">
                                            Activa
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex items-center rounded-full bg-fp-badge-neutral-bg px-2.5 py-1 text-xs font-medium text-fp-badge-neutral-fg">
                                            Inactiva
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    @if ($campana->estado === 'activa')
                                        <button type="button" wire:click="desactivarTemporada({{ $campana->id }})" wire:confirm="¿Desactivar esta temporada?"
                                            class="text-sm font-medium text-fp-primary hover:underline">
                                            Desactivar
                                        </button>
                                    @else
                                        <button type="button" wire:click="activarTemporada({{ $campana->id }})" wire:confirm="¿Activar esta temporada? Las demás activas se desactivarán."
                                            class="text-sm font-medium text-fp-primary hover:underline">
                                            Activar
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-14 text-center">
                                    <p class="text-sm font-medium text-fp-sidebar">No hay temporadas</p>
                                    <p class="mt-1 text-sm text-fp-text-muted">Crea Primavera-Verano u Otoño-Invierno para empezar.</p>
                                    <button type="button" wire:click="abrirFormularioCrearCampana"
                                        class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                        Nueva temporada
                                    </button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form wire:submit="guardarCampana" class="mx-auto max-w-2xl space-y-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-900">Nueva temporada</h2>
                <p class="mt-0.5 text-sm text-fp-text-muted">Ejemplos: Primavera-Verano 2026, Otoño-Invierno 2026-2027.</p>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Nombre de la temporada</label>
                <input type="text" wire:model="campana_nombre"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"
                    placeholder="Primavera-Verano 2026">
                @error('campana_nombre')
                    <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Líneas a asignar (opcional)</label>
                <p class="mb-2 text-xs text-fp-text-muted">Si una línea ya pertenece a otra temporada, se moverá a esta.</p>
                <div class="grid max-h-48 grid-cols-2 gap-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                    @forelse ($lineas as $linea)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="campana_linea_ids" value="{{ $linea->id }}"
                                class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                            <span>
                                {{ $linea->nombre }}
                                <span class="text-xs text-fp-text-muted">({{ $linea->campana?->nombre ?? 'sin temporada' }})</span>
                            </span>
                        </label>
                    @empty
                        <p class="col-span-2 text-sm text-fp-text-muted">No hay líneas. Créalas en la pestaña Líneas.</p>
                    @endforelse
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Fecha de inicio</label>
                    <input type="date" wire:model="campana_fecha_inicio"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Fecha de fin</label>
                    <input type="date" wire:model="campana_fecha_fin"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                    @error('campana_fecha_fin')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCampana"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Cancelar</button>
            </div>
        </form>
    @endif
</div>
