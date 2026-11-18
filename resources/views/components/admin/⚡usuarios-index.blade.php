<?php

use App\Models\Distribuidora;
use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| TG-335 (K37) - Personal de la plataforma (solo lectura).
|
| Crear, editar y dar de baja son K38, K39 y K40. Los filtros van en la
| dirección para que «atrás» desde la ficha regrese a la misma búsqueda.
*/
new #[Layout('layouts.admin')] #[Title('Usuarios — Admin')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(as: 'rol', except: '')]
    public string $filtro_rol = '';

    #[Url(as: 'estado', except: '')]
    public string $filtro_estado = '';

    #[Url(as: 'distribuidora', except: '')]
    public string $filtro_distribuidora = '';

    /** En cada petición, y sin cambiar el equipo de permisos. */
    public function boot(): void
    {
        abort_unless(Auth::check() && PersonalPlataforma::esAdminGeneral(Auth::user()), 403, 'Solo admin general.');
    }

    public function updated(string $propiedad): void
    {
        if (str_starts_with($propiedad, 'filtro_') || $propiedad === 'buscar') {
            $this->resetPage();
        }
    }

    public function limpiarFiltros(): void
    {
        $this->reset('buscar', 'filtro_rol', 'filtro_estado', 'filtro_distribuidora');
        $this->resetPage();
    }

    #[Computed]
    public function personal()
    {
        return PersonalPlataforma::consulta(
            $this->buscar,
            $this->filtro_rol,
            $this->filtro_estado,
            $this->filtro_distribuidora
        )->paginate(20);
    }

    #[Computed]
    public function roles(): array
    {
        return PersonalPlataforma::rolesDe($this->personal->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Computed]
    public function distribuidoras()
    {
        return Distribuidora::query()
            ->orderBy('nombre_comercial')
            ->get(['id', 'nombre_comercial', 'razon_social']);
    }
};
?>

<div>
    <x-panel.encabezado eyebrow="Personal" titulo="Usuarios"
        subtitulo="Admins y empleados de toda la plataforma. Los clientes no aparecen aquí." class="mb-6" />

    <x-personas.tarjeta titulo="{{ $this->personal->total() }} {{ $this->personal->total() === 1 ? 'persona' : 'personas' }}" data-lista="personal">
        <x-ui.cargando objetivo="buscar,filtro_rol,filtro_estado,filtro_distribuidora,limpiarFiltros,gotoPage,nextPage,previousPage" />

        <x-slot:filtros>
            <x-ui.buscador class="min-w-[14rem] flex-1" wire:model.live.debounce.300ms="buscar"
                placeholder="Buscar por nombre, correo o teléfono…" etiqueta="Buscar personal" />
            <x-ui.selector ancho="auto" wire:model.live="filtro_rol" vacio="Todos los roles" aria-label="Filtrar por rol">
                @foreach (\App\Support\PersonalPlataforma::ROLES as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </x-ui.selector>
            <x-ui.selector ancho="auto" wire:model.live="filtro_estado" vacio="Todos los estados" aria-label="Filtrar por estado">
                @foreach (\App\Support\PersonalPlataforma::ESTADOS as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </x-ui.selector>
            <x-ui.selector ancho="auto" wire:model.live="filtro_distribuidora" vacio="Todas las distribuidoras" aria-label="Filtrar por distribuidora">
                @foreach ($this->distribuidoras as $distribuidora)
                    <option value="{{ $distribuidora->id }}">{{ $distribuidora->nombre_comercial ?: $distribuidora->razon_social }}</option>
                @endforeach
            </x-ui.selector>
            <x-panel.boton variante="secundario" wire:click="limpiarFiltros">Limpiar</x-panel.boton>
        </x-slot:filtros>

        <table class="fp-tabla-personas">
            <thead>
                <tr>
                    <th scope="col">Foto</th>
                    <th scope="col">Nombre</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Roles</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Alta</th>
                    <th scope="col" class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->personal as $persona)
                    @php($nombre = $persona->nombre ?: 'Sin nombre')
                    <tr class="fp-fila-persona" wire:key="personal-{{ $persona->id }}" data-persona="{{ $persona->id }}">
                        <td class="fp-celda-avatar w-11 pr-0"><x-ui.avatar :url="$persona->foto_url" :nombre="$nombre" /></td>
                        <td class="fp-celda-nombre">{{ $nombre }}</td>
                        <td class="fp-celda-correo" data-etiqueta="Correo">{{ $persona->email }}</td>
                        <td><x-personas.roles :roles="$this->roles[$persona->id] ?? []" /></td>
                        <td><x-personas.estado :estado="$persona->estado" /></td>
                        <td class="whitespace-nowrap tabular-nums" data-etiqueta="Alta">{{ $persona->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="fp-celda-acciones">
                            <div class="fp-acciones-persona">
                                <x-personas.accion etiqueta="Ver ficha" :persona="$nombre" :href="route('admin.usuarios.ficha', $persona->id)" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="7" mensaje="No hay personal con estos filtros." />
                @endforelse
            </tbody>
        </table>

        @if ($this->personal->hasPages())
            <div class="mt-4 border-t border-slate-100 pt-3">
                {{ $this->personal->links() }}
            </div>
        @endif
    </x-personas.tarjeta>
</div>
