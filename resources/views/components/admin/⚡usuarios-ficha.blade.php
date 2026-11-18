<?php

use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| TG-335 (K37) - Ficha de solo lectura de una persona del personal.
|
| Un cliente no tiene ficha aquí: su id responde 404.
*/
new #[Layout('layouts.admin')] #[Title('Ficha de usuario — Admin')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $usuarioId;

    /** En cada petición, y sin cambiar el equipo de permisos. */
    public function boot(): void
    {
        abort_unless(Auth::check() && PersonalPlataforma::esAdminGeneral(Auth::user()), 403, 'Solo admin general.');
    }

    public function mount(int $usuario): void
    {
        $this->usuarioId = $usuario;
        abort_if($this->persona === null, 404);
    }

    #[Computed]
    public function persona()
    {
        return PersonalPlataforma::consulta()->whereKey($this->usuarioId)->first();
    }

    #[Computed]
    public function roles(): array
    {
        return PersonalPlataforma::rolesDe([$this->usuarioId])[$this->usuarioId] ?? [];
    }

    #[Computed]
    public function ultimoInicio()
    {
        return PersonalPlataforma::ultimoInicio($this->usuarioId);
    }

    #[Computed]
    public function movimientos()
    {
        return PersonalPlataforma::movimientos($this->usuarioId)->paginate(15);
    }
};
?>

<div class="space-y-6">
    @php($persona = $this->persona)
    @php($nombre = $persona->nombre ?: 'Sin nombre')

    <x-panel.encabezado :volver="route('admin.usuarios')" volver-texto="← Usuarios" :titulo="$nombre"
        subtitulo="Ficha de solo lectura." />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-panel.seccion titulo="Datos">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:p-5">
                <x-ui.avatar :url="$persona->foto_url" :nombre="$nombre" tamano="h-20 w-20 text-xl" />
                <dl class="grid min-w-0 flex-1 grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="min-w-0">
                        <dt class="text-xs text-fp-text-muted">Correo</dt>
                        <dd class="break-words text-slate-800">{{ $persona->email }}</dd>
                        <x-ui.estado-correo :usuario="$persona" />
                    </div>
                    <div>
                        <dt class="text-xs text-fp-text-muted">Teléfono</dt>
                        <dd class="text-slate-800">{{ $persona->telefono ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fp-text-muted">Estado</dt>
                        <dd class="mt-0.5"><x-personas.estado :estado="$persona->estado" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fp-text-muted">Alta</dt>
                        <dd class="tabular-nums text-slate-800">{{ $persona->created_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2" data-ultimo-inicio>
                        <dt class="text-xs text-fp-text-muted">Último inicio de sesión</dt>
                        @if ($this->ultimoInicio)
                            <dd class="text-slate-800">
                                <span class="tabular-nums">{{ $this->ultimoInicio->created_at?->format('d/m/Y H:i') }}</span>
                                <span class="text-xs text-slate-500">· {{ \App\Support\Auditoria\EtiquetasAuditoria::etiqueta($this->ultimoInicio->accion) }}</span>
                            </dd>
                        @else
                            <dd class="text-slate-500">Sin registro</dd>
                        @endif
                    </div>
                </dl>
            </div>
        </x-panel.seccion>

        <x-panel.seccion titulo="Roles por distribuidora">
            <div class="p-4 sm:p-5">
                <x-personas.roles :roles="$this->roles" class="space-y-2" />
            </div>
        </x-panel.seccion>
    </div>

    <x-panel.seccion titulo="Movimientos auditados" nota="Lo que hizo esta persona, del más reciente al más antiguo." data-movimientos>
        <div class="relative">
            <x-ui.cargando objetivo="gotoPage,nextPage,previousPage" />
            <div class="overflow-x-auto">
                <table class="fp-tabla min-w-full text-sm">
                    <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Fecha</th>
                            <th scope="col" class="px-4 py-3 font-medium">Acción</th>
                            <th scope="col" class="px-4 py-3 font-medium">Entidad</th>
                            <th scope="col" class="px-4 py-3 font-medium">Distribuidora</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->movimientos as $movimiento)
                            <tr class="hover:bg-slate-50/80" wire:key="movimiento-{{ $movimiento->id }}">
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ $movimiento->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-fp-text-muted">{{ \App\Support\Auditoria\EtiquetasAuditoria::area($movimiento->accion) }}</div>
                                    <x-panel.insignia variante="info">{{ \App\Support\Auditoria\EtiquetasAuditoria::etiqueta($movimiento->accion) }}</x-panel.insignia>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $movimiento->entidad_tipo }}
                                    @if ($movimiento->entidad_id)
                                        <span class="text-slate-500">#{{ $movimiento->entidad_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ $movimiento->distribuidora?->nombre_comercial ?: ($movimiento->distribuidora?->razon_social ?: '—') }}</td>
                            </tr>
                        @empty
                            <x-panel.vacio colspan="4" mensaje="Esta persona no tiene movimientos auditados." icono="M9 12l2 2 4-4M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->movimientos->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $this->movimientos->links() }}
                </div>
            @endif
        </div>
    </x-panel.seccion>
</div>
