<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Marca;
use App\Services\Catalogo\GestionarMarcaAction;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ?string $errorNegocio = null;

    public $marcas = [];
    public ?int $marcaEditandoId = null;
    public bool $mostrandoFormularioMarca = false;
    public string $marca_nombre = '';
    public ?string $marca_descripcion = null;
    public bool $marca_activa = true;
    public $marca_logotipo = null;
    public ?string $marca_logotipo_url_actual = null;

    public function mount(): void
    {
        $this->cargarMarcas();
    }

    private function cargarMarcas(): void
    {
        $this->marcas = Marca::all();
    }

    public function abrirFormularioCrearMarca(): void
    {
        $this->marcaEditandoId = null;
        $this->marca_nombre = '';
        $this->marca_descripcion = null;
        $this->marca_activa = true;
        $this->marca_logotipo = null;
        $this->marca_logotipo_url_actual = null;
        $this->errorNegocio = null;
        $this->mostrandoFormularioMarca = true;
    }

    public function abrirFormularioEditarMarca(int $id): void
    {
        $marca = Marca::findOrFail($id);
        $this->marcaEditandoId = $marca->id;
        $this->marca_nombre = $marca->nombre;
        $this->marca_descripcion = $marca->descripcion;
        $this->marca_activa = (bool) $marca->activa;
        $this->marca_logotipo = null;
        $this->marca_logotipo_url_actual = $marca->logotipo_url;
        $this->errorNegocio = null;
        $this->mostrandoFormularioMarca = true;
    }

    public function cancelarFormularioMarca(): void
    {
        $this->mostrandoFormularioMarca = false;
        $this->marcaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarMarca(): void
    {
        $this->errorNegocio = null;
        $datos = $this->validate([
            'marca_nombre' => ['required', 'string', 'max:120'],
            'marca_descripcion' => ['nullable', 'string'],
            'marca_logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);
        $payload = [
            'nombre' => $datos['marca_nombre'],
            'descripcion' => $datos['marca_descripcion'],
            'activa' => $this->marca_activa,
        ];
        $accion = app(GestionarMarcaAction::class);
        try {
            if ($this->marcaEditandoId) {
                $accion->actualizar(Marca::findOrFail($this->marcaEditandoId), $payload, $this->marca_logotipo);
            } else {
                $accion->crear($payload, $this->marca_logotipo);
            }
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }
        $this->marca_logotipo = null;
        $this->mostrandoFormularioMarca = false;
        $this->marcaEditandoId = null;
        $this->cargarMarcas();
        $this->dispatch('guardado', mensaje: 'Marca guardada correctamente.');
    }

};
?>

<div>
    @if ($errorNegocio)
        <div class="mb-4 rounded-xl border border-fp-badge-danger-fg/15 bg-fp-badge-danger-bg px-4 py-3 text-sm text-fp-badge-danger-fg">
            {{ $errorNegocio }}</div>
    @endif
    @if (!$mostrandoFormularioMarca)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-slate-900">Marcas</h2>
                    <p class="mt-0.5 text-sm text-fp-text-muted">Logotipos y estado de las marcas del catálogo.</p>
                </div>
                <button type="button" wire:click="abrirFormularioCrearMarca"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nueva marca
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-fp-page/80 text-left text-[11px] font-semibold uppercase tracking-wide text-fp-text-muted">
                            <th class="px-6 py-3"></th>
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($marcas as $marca)
                            <tr class="transition-colors hover:bg-fp-page/70">
                                <td class="px-6 py-3.5">
                                    @if ($marca->logotipo_url)
                                        <img src="{{ $marca->logotipo_url }}" alt="" class="h-9 w-9 rounded-lg object-cover ring-1 ring-slate-200/80">
                                    @else
                                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-fp-page text-[11px] font-semibold text-fp-text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 font-medium text-slate-800">{{ $marca->nombre }}</td>
                                <td class="px-6 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $marca->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $marca->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <button type="button"
                                        wire:click="abrirFormularioEditarMarca({{ $marca->id }})"
                                        class="text-sm font-medium text-fp-primary hover:underline">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-14 text-center">
                                    <p class="text-sm font-medium text-fp-sidebar">Aún no hay marcas</p>
                                    <p class="mt-1 text-sm text-fp-text-muted">Registra la primera marca de tu catálogo.</p>
                                    <button type="button" wire:click="abrirFormularioCrearMarca"
                                        class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                        Nueva marca
                                    </button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form wire:submit="guardarMarca" class="mx-auto max-w-2xl space-y-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    {{ $marcaEditandoId ? 'Editar marca' : 'Nueva marca' }}</h2>
                <p class="mt-0.5 text-sm text-fp-text-muted">
                    {{ $marcaEditandoId ? 'Actualiza nombre, descripción y logotipo.' : 'Define la marca y, si quieres, su logotipo.' }}
                </p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Nombre</label>
                <input type="text" wire:model="marca_nombre"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                @error('marca_nombre')
                    <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Descripción</label>
                <textarea wire:model="marca_descripcion" rows="2"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"></textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Logotipo</label>
                @if ($marca_logotipo)
                    <img src="{{ $marca_logotipo->temporaryUrl() }}" alt="" class="mb-2 h-16 w-16 rounded-xl object-cover ring-1 ring-slate-200/80">
                @elseif ($marca_logotipo_url_actual)
                    <img src="{{ $marca_logotipo_url_actual }}" alt="" class="mb-2 h-16 w-16 rounded-xl object-cover ring-1 ring-slate-200/80">
                @endif
                <input type="file" wire:model="marca_logotipo" accept="image/png,image/jpeg"
                    class="block w-full text-sm text-fp-text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-fp-page file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200/70">
                @error('marca_logotipo')
                    <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                @enderror
            </div>
            @if ($marcaEditandoId)
                <label class="flex items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" wire:model="marca_activa"
                        class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                    Marca activa
                </label>
            @endif
            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioMarca"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Cancelar</button>
            </div>
        </form>
    @endif
</div>
