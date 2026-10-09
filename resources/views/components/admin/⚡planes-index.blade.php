<?php

use App\Models\PlanSuscripcion;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Planes — Admin')] class extends Component
{
    public string $mensaje = '';

    // Formulario crear/editar
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
            'descripcion'         => 'nullable|string',
            'precio_base_mensual' => 'required|numeric|min:0',
            'lineas_incluidas'    => 'required|integer|min:0',
            'precio_linea_extra'  => 'required|numeric|min:0',
            'activo'              => 'boolean',
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
                class="rounded-lg bg-fp-primary text-white text-sm font-medium px-4 py-2 shadow-sm hover:bg-fp-primary/90">
            Nuevo plan
        </button>
        </x-slot:acciones>
    </x-panel.encabezado>

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($mostrarForm)
        <div class="mb-6 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
            <h3 class="font-semibold mb-4">{{ $editId ? 'Editar plan' : 'Nuevo plan' }}</h3>
            <form wire:submit="guardar" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Nombre</label>
                    <input type="text" wire:model="nombre" class="w-full rounded-lg border-slate-300">
                    @error('nombre') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Líneas incluidas</label>
                    <input type="number" wire:model="lineas_incluidas" class="w-full rounded-lg border-slate-300">
                    @error('lineas_incluidas') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Precio base mensual</label>
                    <input type="number" step="0.01" wire:model="precio_base_mensual" class="w-full rounded-lg border-slate-300">
                    @error('precio_base_mensual') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Precio línea extra</label>
                    <input type="number" step="0.01" wire:model="precio_linea_extra" class="w-full rounded-lg border-slate-300">
                    @error('precio_linea_extra') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Descripción</label>
                    <textarea wire:model="descripcion" rows="2" class="w-full rounded-lg border-slate-300"></textarea>
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="checkbox" wire:model="activo" class="rounded border-slate-300 text-fp-primary">
                    <span class="text-sm">Plan activo</span>
                </div>
                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="rounded-lg bg-fp-primary text-white text-sm font-medium px-4 py-2 shadow-sm hover:bg-fp-primary/90">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelar" class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
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
                @foreach ($this->planes as $plan)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $plan->nombre }}</div>
                            <div class="text-xs text-slate-400">{{ Str::limit($plan->descripcion, 40) }}</div>
                        </td>
                        <td class="px-4 py-3">${{ number_format($plan->precio_base_mensual, 2) }}</td>
                        <td class="px-4 py-3">{{ $plan->lineas_incluidas }}</td>
                        <td class="px-4 py-3">${{ number_format($plan->precio_linea_extra, 2) }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $plan->activo ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                {{ $plan->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            <button wire:click="editar({{ $plan->id }})" class="text-xs text-fp-primary hover:underline">Editar</button>
                            @if ($plan->activo)
                                <button wire:click="desactivar({{ $plan->id }})" class="text-xs text-fp-danger hover:underline">Desactivar</button>
                            @else
                                <button wire:click="activar({{ $plan->id }})" class="text-xs text-fp-badge-success-fg hover:underline">Activar</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>