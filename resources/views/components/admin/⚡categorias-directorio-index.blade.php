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
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Categorías del directorio</h2>
            <p class="text-sm text-slate-500">
                Con ellas se filtran las distribuidoras en el marketplace. Se asignan desde «Ver datos» en Distribuidoras.
            </p>
        </div>
        <button wire:click="nuevo"
                class="rounded-lg bg-[#111E38] text-white text-sm px-4 py-2 hover:bg-[#1E2F52]">
            Nueva categoría
        </button>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    @if ($mostrarForm)
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-5 max-w-xl">
            <h3 class="font-semibold mb-4">{{ $editId ? 'Editar categoría' : 'Nueva categoría' }}</h3>
            <form wire:submit="guardar" class="space-y-4">
                <div>
                    <label for="nombre-categoria" class="block text-sm font-medium mb-1">Nombre</label>
                    <input id="nombre-categoria" type="text" wire:model="nombre"
                        maxlength="{{ \App\Models\CategoriaDirectorio::LARGO_MAXIMO_NOMBRE }}"
                        placeholder="Por ejemplo: Calzado infantil"
                        class="w-full rounded-lg border-slate-300">
                    @error('nombre') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="activa" class="rounded border-slate-300 text-blue-600">
                    Activa (se muestra en el marketplace)
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-[#111E38] text-white text-sm px-4 py-2" wire:loading.attr="disabled">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelar" class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
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
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $categoria->activa ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $categoria->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            <button wire:click="editar({{ $categoria->id }})" class="text-xs text-blue-700 hover:underline">Editar</button>
                            @if ($categoria->activa)
                                <button wire:click="desactivar({{ $categoria->id }})"
                                    wire:confirm="Dejará de mostrarse en el marketplace. Sus distribuidoras la conservan por si la activas de nuevo. ¿Continuar?"
                                    class="text-xs text-red-600 hover:underline">Desactivar</button>
                            @else
                                <button wire:click="activar({{ $categoria->id }})" class="text-xs text-green-700 hover:underline">Activar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-400">
                            Aún no hay categorías. Crea la primera con «Nueva categoría».
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
