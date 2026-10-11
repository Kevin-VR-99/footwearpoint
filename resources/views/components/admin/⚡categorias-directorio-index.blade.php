<?php

use App\Models\CategoriaDirectorio;
use App\Services\Directorio\CambiarEstadoCategoriaDirectorioAction;
use App\Services\Directorio\GuardarCategoriaDirectorioAction;
use App\Support\MensajeError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * TG-197 (G16) — Categorías generales del directorio (E2-05).
 *
 * Solo el admin general. Las categorías no se borran: se desactivan, y una
 * inactiva deja de verse en el marketplace sin perder sus distribuidoras.
 * Se asignan a cada distribuidora desde "Ver datos" en Distribuidoras.
 */
new #[Layout('layouts.admin')] #[Title('Categorías del directorio — Admin')] class extends Component
{
    public string $mensaje = '';

    public ?int $editId = null;
    public string $nombre = '';
    public bool $activa = true;
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

    public function getCategoriasProperty()
    {
        return CategoriaDirectorio::query()
            ->withCount('distribuidoras')
            ->orderBy('nombre')
            ->get();
    }

    public function nuevo(): void
    {
        $this->resetForm();
        $this->mensaje = '';
        $this->mostrarForm = true;
    }

    public function editar(int $id): void
    {
        $categoria = CategoriaDirectorio::findOrFail($id);

        $this->resetErrorBag();
        $this->mensaje = '';
        $this->editId = $categoria->id;
        $this->nombre = $categoria->nombre;
        $this->activa = (bool) $categoria->activa;
        $this->mostrarForm = true;
    }

    public function guardar(): void
    {
        $this->mensaje = '';
        $this->resetErrorBag();

        try {
            $categoria = app(GuardarCategoriaDirectorioAction::class)->ejecutar(
                ['nombre' => $this->nombre, 'activa' => $this->activa],
                $this->editId ? CategoriaDirectorio::findOrFail($this->editId) : null,
            );
        } catch (ValidationException $e) {
            $this->addError('nombre', collect($e->errors())->flatten()->first() ?? GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_OBLIGATORIO);
            return;
        } catch (\Throwable $e) {
            // TG-224 (G3): el detalle técnico va al log, nunca a la pantalla.
            $this->addError('nombre', MensajeError::paraUsuario($e, 'No se pudo guardar la categoría. Intenta de nuevo.'));
            return;
        }

        $this->mensaje = $this->editId
            ? "Categoría «{$categoria->nombre}» actualizada."
            : "Categoría «{$categoria->nombre}» creada.";

        $this->resetForm();
    }

    public function desactivar(int $id): void
    {
        $this->cambiarEstado($id, false);
    }

    public function activar(int $id): void
    {
        $this->cambiarEstado($id, true);
    }

    public function cancelar(): void
    {
        $this->resetForm();
    }

    private function cambiarEstado(int $id, bool $activa): void
    {
        try {
            $categoria = app(CambiarEstadoCategoriaDirectorioAction::class)->ejecutar(CategoriaDirectorio::findOrFail($id), $activa);
        } catch (\Throwable $e) {
            $this->mensaje = MensajeError::paraUsuario($e, 'No se pudo cambiar la categoría. Intenta de nuevo.');
            return;
        }

        $this->mensaje = $activa
            ? "Categoría «{$categoria->nombre}» activada."
            : "Categoría «{$categoria->nombre}» desactivada. Ya no se muestra en el marketplace.";
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->editId = null;
        $this->nombre = '';
        $this->activa = true;
        $this->mostrarForm = false;
    }
};
?>

<div>
    <x-panel.encabezado titulo="Categorías del directorio" class="mb-6">
            <p class="mt-1 text-sm text-fp-text-muted">
                Con ellas se filtran las distribuidoras en el marketplace. Se asignan desde «Ver datos» en Distribuidoras.
            </p>
        <x-slot:acciones>
        <button wire:click="nuevo"
                class="rounded-lg bg-fp-primary text-white text-sm font-medium px-4 py-2 shadow-sm hover:bg-fp-primary/90">
            Nueva categoría
        </button>
        </x-slot:acciones>
    </x-panel.encabezado>

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($mostrarForm)
        <div class="mb-6 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm max-w-xl">
            <h3 class="font-semibold mb-4">{{ $editId ? 'Editar categoría' : 'Nueva categoría' }}</h3>
            <form wire:submit="guardar" class="space-y-4">
                <div>
                    <label for="nombre-categoria" class="block text-sm font-medium mb-1">Nombre</label>
                    <input id="nombre-categoria" type="text" wire:model="nombre"
                        maxlength="{{ \App\Models\CategoriaDirectorio::LARGO_MAXIMO_NOMBRE }}"
                        placeholder="Por ejemplo: Calzado infantil"
                        class="w-full rounded-lg border-slate-300">
                    @error('nombre') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="activa" class="rounded border-slate-300 text-fp-primary">
                    Activa (se muestra en el marketplace)
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-fp-primary text-white text-sm font-medium px-4 py-2 shadow-sm hover:bg-fp-primary/90" wire:loading.attr="disabled">
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
                    <th class="text-left px-4 py-3 font-medium">Categoría</th>
                    <th class="text-left px-4 py-3 font-medium">Distribuidoras</th>
                    <th class="text-left px-4 py-3 font-medium">Estado</th>
                    <th class="text-left px-4 py-3 font-medium">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->categorias as $categoria)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $categoria->nombre }}</td>
                        <td class="px-4 py-3">{{ $categoria->distribuidoras_count }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $categoria->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                {{ $categoria->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            <button wire:click="editar({{ $categoria->id }})" class="text-xs text-fp-primary hover:underline">Editar</button>
                            @if ($categoria->activa)
                                <button wire:click="desactivar({{ $categoria->id }})"
                                    wire:confirm="Dejará de mostrarse en el marketplace. Sus distribuidoras la conservan por si la activas de nuevo. ¿Continuar?"
                                    class="text-xs text-fp-danger hover:underline">Desactivar</button>
                            @else
                                <button wire:click="activar({{ $categoria->id }})" class="text-xs text-fp-badge-success-fg hover:underline">Activar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-fp-text-muted">
                            Aún no hay categorías. Crea la primera con «Nueva categoría».
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
