<?php

use App\Models\PlanSuscripcion;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Planes — Admin')] class extends Component
{
    public string $mensaje = '';

    // Formulario crear/editar
    #[Locked]
    public ?int $editId = null;
    public string $nombre = '';
    public string $descripcion = '';
    public string $precio_base_mensual = '';
    public string $lineas_incluidas = '';
    public string $precio_linea_extra = '150';
    public bool $activo = true;
    public bool $mostrarForm = false;

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(0);

        if (!Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    public function getPlanesProperty()
    {
        return PlanSuscripcion::orderBy('precio_base_mensual')->get();
    }

    /** Métricas de la cabecera: planes y suscripciones activas por plan. */
    public function getResumenProperty(): array
    {
        $planes = $this->planes;
        $suscripciones = \App\Models\Suscripcion::withoutGlobalScopes()->where('estado', 'activa')->count();

        return [
            'activos' => $planes->where('activo', true)->count(),
            'total' => $planes->count(),
            'suscripciones' => $suscripciones,
            'desde' => $planes->where('activo', true)->min('precio_base_mensual'),
        ];
    }

    public function nuevo()
    {
        $this->resetForm();
        $this->mostrarForm = true;
    }

    public function editar(int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);

        $this->editId = $plan->id;
        $this->nombre = $plan->nombre;
        $this->descripcion = $plan->descripcion ?? '';
        $this->precio_base_mensual = (string) $plan->precio_base_mensual;
        $this->lineas_incluidas = (string) $plan->lineas_incluidas;
        $this->precio_linea_extra = (string) $plan->precio_linea_extra;
        $this->activo = (bool) $plan->activo;
        $this->mostrarForm = true;
    }

    public function guardar()
    {
        $this->validate([
            'nombre'              => 'required|string|max:100',
            'descripcion'         => 'nullable|string|max:2000',
            'precio_base_mensual' => 'required|numeric|min:0',
            'lineas_incluidas'    => 'required|integer|min:0',
            'precio_linea_extra'  => 'required|numeric|min:0',
            'activo'              => 'boolean',
        ], [
            'descripcion.max' => 'La descripción no puede tener más de 2000 caracteres.',
        ]);

        $data = [
            'nombre'              => $this->nombre,
            'descripcion'         => $this->descripcion ?: null,
            'precio_base_mensual' => $this->precio_base_mensual,
            'lineas_incluidas'    => $this->lineas_incluidas,
            'precio_linea_extra'  => $this->precio_linea_extra,
            'activo'              => $this->activo,
        ];

        if ($this->editId) {
            PlanSuscripcion::findOrFail($this->editId)->update($data);
            $this->mensaje = 'Plan actualizado correctamente.';
        } else {
            PlanSuscripcion::create($data);
            $this->mensaje = 'Plan creado correctamente.';
        }

        $this->resetForm();
    }

    public function desactivar(int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);
        $plan->update(['activo' => false]);
        $this->mensaje = "Plan «{$plan->nombre}» desactivado.";
    }

    public function activar(int $id)
    {
        $plan = PlanSuscripcion::findOrFail($id);
        $plan->update(['activo' => true]);
        $this->mensaje = "Plan «{$plan->nombre}» activado.";
    }

    public function cancelar()
    {
        $this->resetForm();
    }

    private function resetForm()
    {
        $this->editId = null;
        $this->nombre = '';
        $this->descripcion = '';
        $this->precio_base_mensual = '';
        $this->lineas_incluidas = '';
        $this->precio_linea_extra = '150';
        $this->activo = true;
        $this->mostrarForm = false;
    }
};
?>

<div>
    <x-panel.encabezado titulo="Planes de suscripción" class="mb-6">
            <p class="mt-1 text-sm text-fp-text-muted">Precios base y línea extra ($150)</p>
        <x-slot:acciones>
        <button wire:click="nuevo"
                class="fp-btn fp-btn-primario">
            Nuevo plan
        </button>
        </x-slot:acciones>
    </x-panel.encabezado>

    @php($resumen = $this->resumen)
    <div class="fp-escalonado mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4" data-metricas-admin>
        <x-panel.metrica titulo="Planes activos" :valor="$resumen['activos'].' / '.$resumen['total']" tono="exito"
            icono="M3 7h18v10H3zM3 11h18" :progreso="$resumen['total'] ? $resumen['activos'] / $resumen['total'] * 100 : 0" />
        <x-panel.metrica titulo="Suscripciones activas" :valor="$resumen['suscripciones']" detalle="Distribuidoras con plan vigente"
            icono="M9 12l2 2 4-4M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z" />
        <x-panel.metrica titulo="Plan más económico" :valor="$resumen['desde'] !== null ? '$'.number_format((float) $resumen['desde'], 2) : '—'" tono="neutral" detalle="Precio base mensual"
            icono="M12 8c-2 0-3 .9-3 2s1 2 3 2 3 .9 3 2-1 2-3 2m0-8V6m0 10v2" />
    </div>

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($mostrarForm)
        <div class="fp-formulario fp-aparecer mb-6 p-5">
            <h3 class="mb-4 text-lg font-semibold text-fp-sidebar">{{ $editId ? 'Editar plan' : 'Nuevo plan' }}</h3>
            <form wire:submit="guardar" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="fp-campo">
                    <label for="planes-index-nombre" class="block text-sm font-medium mb-1 text-slate-700">Nombre</label>
                    <input id="planes-index-nombre" type="text" wire:model="nombre" class="w-full rounded-lg">
                    @error('nombre') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="fp-campo">
                    <label for="planes-index-lineas-incluidas" class="block text-sm font-medium mb-1 text-slate-700">Líneas incluidas</label>
                    <input id="planes-index-lineas-incluidas" type="number" wire:model="lineas_incluidas" class="w-full rounded-lg">
                    @error('lineas_incluidas') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="fp-campo">
                    <label for="planes-index-precio-base-mensual" class="block text-sm font-medium mb-1 text-slate-700">Precio base mensual</label>
                    <input id="planes-index-precio-base-mensual" type="number" step="0.01" wire:model="precio_base_mensual" class="w-full rounded-lg">
                    @error('precio_base_mensual') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="fp-campo">
                    <label for="planes-index-precio-linea-extra" class="block text-sm font-medium mb-1 text-slate-700">Precio línea extra</label>
                    <input id="planes-index-precio-linea-extra" type="number" step="0.01" wire:model="precio_linea_extra" class="w-full rounded-lg">
                    @error('precio_linea_extra') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="fp-campo md:col-span-2">
                    <label for="planes-index-descripcion" class="block text-sm font-medium mb-1 text-slate-700">Descripción</label>
                    <textarea id="planes-index-descripcion" wire:model="descripcion" rows="2" class="w-full rounded-lg"></textarea>
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="checkbox" wire:model="activo" class="rounded text-fp-primary">
                    <span class="text-sm">Plan activo</span>
                </div>
                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="fp-btn fp-btn-primario">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelar" class="fp-btn fp-btn-secundario">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="fp-tarjeta fp-tabla overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">Plan</th>
                    <th class="text-left px-4 py-3 font-medium">Base</th>
                    <th class="text-left px-4 py-3 font-medium">Líneas</th>
                    <th class="text-left px-4 py-3 font-medium">Línea extra</th>
                    <th class="text-left px-4 py-3 font-medium">Estado</th>
                    <th class="text-left px-4 py-3 font-medium">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->planes as $plan)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $plan->nombre }}</div>
                            <div class="text-xs text-slate-400">{{ Str::limit($plan->descripcion, 40) }}</div>
                        </td>
                        <td class="px-4 py-3">${{ number_format($plan->precio_base_mensual, 2) }}</td>
                        <td class="px-4 py-3">{{ $plan->lineas_incluidas }}</td>
                        <td class="px-4 py-3">${{ number_format($plan->precio_linea_extra, 2) }}</td>
                        <td class="px-4 py-3">
                            <x-panel.insignia :variante="$plan->activo ? 'success' : 'neutral'" punto>{{ $plan->activo ? 'Activo' : 'Inactivo' }}</x-panel.insignia>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button wire:click="editar({{ $plan->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                            @if ($plan->activo)
                                <button type="button" wire:click="desactivar({{ $plan->id }})" wire:confirm="¿Desactivar el plan {{ $plan->nombre }}? Ya no se podrá asignar a nuevas distribuidoras." class="fp-accion fp-accion-peligro">Desactivar</button>
                            @else
                                <button wire:click="activar({{ $plan->id }})" class="fp-accion fp-accion-exito">Activar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="6" mensaje="Aún no hay planes. Crea el primero con «Nuevo plan»." />
                @endforelse
            </tbody>
        </table>
    </div>
</div>