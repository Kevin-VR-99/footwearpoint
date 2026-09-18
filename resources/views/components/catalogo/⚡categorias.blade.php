<?php

use App\Models\CategoriaProducto;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use Livewire\Component;

new class extends Component {
    public $categorias = [];
    public ?int $categoriaEditandoId = null;
    public bool $mostrandoFormularioCategoria = false;
    public string $categoria_nombre = '';
    public ?string $categoria_descripcion = null;
    public bool $categoria_activa = true;

    public function mount(): void
    {
        $this->cargarCategorias();
    }

    private function cargarCategorias(): void
    {
        $this->categorias = CategoriaProducto::all();
    }

    public function abrirFormularioCrearCategoria(): void
    {
        $this->categoriaEditandoId = null;
        $this->categoria_nombre = '';
        $this->categoria_descripcion = null;
        $this->categoria_activa = true;
        $this->mostrandoFormularioCategoria = true;
    }

    public function abrirFormularioEditarCategoria(int $id): void
    {
        $categoria = CategoriaProducto::findOrFail($id);
        $this->categoriaEditandoId = $categoria->id;
        $this->categoria_nombre = $categoria->nombre;
        $this->categoria_descripcion = $categoria->descripcion;
        $this->categoria_activa = (bool) $categoria->activa;
        $this->mostrandoFormularioCategoria = true;
    }

    public function cancelarFormularioCategoria(): void
    {
        $this->mostrandoFormularioCategoria = false;
        $this->categoriaEditandoId = null;
    }

    public function guardarCategoria(): void
    {
        $datos = $this->validate([
            'categoria_nombre' => ['required', 'string', 'max:120'],
            'categoria_descripcion' => ['nullable', 'string', 'max:300'],
        ]);
        $payload = [
            'nombre' => $datos['categoria_nombre'],
            'descripcion' => $datos['categoria_descripcion'],
            'activa' => $this->categoria_activa,
        ];
        $accion = app(GestionarCategoriaProductoAction::class);
        if ($this->categoriaEditandoId) {
            $accion->actualizar(CategoriaProducto::findOrFail($this->categoriaEditandoId), $payload);
        } else {
            $accion->crear($payload);
        }
        $this->mostrandoFormularioCategoria = false;
        $this->categoriaEditandoId = null;
        $this->cargarCategorias();
        $this->dispatch('guardado', mensaje: 'Categoría guardada correctamente.');
    }

};
?>

<div>
    @if (!$mostrandoFormularioCategoria)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-slate-900">Categorías</h2>
                    <p class="mt-0.5 text-sm text-fp-text-muted">Clasificación de productos del catálogo.</p>
                </div>
                <button type="button" wire:click="abrirFormularioCrearCategoria"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nueva categoría
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-fp-page/80 text-left text-[11px] font-semibold uppercase tracking-wide text-fp-text-muted">
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($categorias as $categoria)
                            <tr class="transition-colors hover:bg-fp-page/70">
                                <td class="px-6 py-3.5 font-medium text-slate-800">{{ $categoria->nombre }}</td>
                                <td class="px-6 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $categoria->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $categoria->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <button type="button"
                                        wire:click="abrirFormularioEditarCategoria({{ $categoria->id }})"
                                        class="text-sm font-medium text-fp-primary hover:underline">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-14 text-center">
                                    <p class="text-sm font-medium text-fp-sidebar">Aún no hay categorías</p>
                                    <p class="mt-1 text-sm text-fp-text-muted">Crea categorías para clasificar productos.</p>
                                    <button type="button" wire:click="abrirFormularioCrearCategoria"
                                        class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                        Nueva categoría
                                    </button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form wire:submit="guardarCategoria" class="mx-auto max-w-2xl space-y-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    {{ $categoriaEditandoId ? 'Editar categoría' : 'Nueva categoría' }}</h2>
                <p class="mt-0.5 text-sm text-fp-text-muted">
                    {{ $categoriaEditandoId ? 'Actualiza los datos de la categoría.' : 'Crea una categoría para clasificar productos.' }}
                </p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Nombre</label>
                <input type="text" wire:model="categoria_nombre"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                @error('categoria_nombre')
                    <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Descripción</label>
                <textarea wire:model="categoria_descripcion" rows="2"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"></textarea>
            </div>
            @if ($categoriaEditandoId)
                <label class="flex items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" wire:model="categoria_activa"
                        class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                    Categoría activa
                </label>
            @endif
            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCategoria"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Cancelar</button>
            </div>
        </form>
    @endif
</div>
