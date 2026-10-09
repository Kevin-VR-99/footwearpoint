<?php

use App\Models\Campana;
use App\Models\ImportacionCatalogo;
use App\Models\Linea;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * TG-237 (A7 / E5-01) — Subir el catálogo de la fábrica para la IA.
 *
 * Solo el admin general: el catálogo maestro es uno solo para todo el
 * sistema y no pertenece a ninguna distribuidora (TG-208).
 *
 * Aquí solo se sube y se deja en "cargado". Leerlo es A8, en una tarea en
 * segundo plano: procesar un PDF con Claude tarda minutos y el servidor
 * corta las peticiones web a los 30 segundos.
 */
new #[Layout('layouts.admin')] #[Title('Catálogos para importar — Admin')] class extends Component
{
    use WithFileUploads;

    public $archivo = null;
    public ?int $linea_id = null;
    public ?int $campana_id = null;

    public string $mensaje = '';
    public bool $esError = false;

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        setPermissionsTeamId(0);

        if (! Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    public function getLineasProperty()
    {
        return Linea::orderBy('nombre')->get();
    }

    /** Las temporadas son de una línea, así que la lista depende de cuál se eligió. */
    public function getTemporadasProperty()
    {
        if (! $this->linea_id) {
            return collect();
        }

        return Campana::where('linea_id', $this->linea_id)->orderByDesc('id')->get();
    }

    public function getImportacionesProperty()
    {
        return ImportacionCatalogo::with(['linea', 'campana', 'iniciadaPor'])
            ->orderByDesc('id')
            ->get();
    }

    /** Al cambiar de línea, la temporada elegida ya no corresponde. */
    public function updatedLineaId(): void
    {
        $this->campana_id = null;
    }

    protected function rules(): array
    {
        $extensiones = implode(',', SubirCatalogoParaImportarAction::EXTENSIONES);

        return [
            'archivo'    => ['required', 'file', 'mimes:' . $extensiones, 'max:' . SubirCatalogoParaImportarAction::MAXIMO_KB],
            'linea_id'   => ['required', 'integer', 'exists:lineas,id'],
            'campana_id' => ['nullable', 'integer', 'exists:campanas,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'archivo.required'  => 'Elige el archivo del catálogo.',
            'archivo.mimes'     => 'Solo se aceptan archivos PDF, JPG o PNG.',
            'archivo.max'       => 'El archivo pesa más de 40 MB. Si el catálogo es más grande, divídelo.',
            'linea_id.required' => 'Elige a qué línea pertenece el catálogo.',
            'linea_id.exists'   => 'Esa línea no existe.',
            'campana_id.exists' => 'Esa temporada no existe.',
        ];
    }

    public function subir(SubirCatalogoParaImportarAction $accion): void
    {
        $this->validate();

        $importacion = $accion->ejecutar($this->archivo, (int) $this->linea_id, $this->campana_id);

        $this->reset(['archivo', 'campana_id']);

        $this->mensaje = 'Catálogo cargado (#' . $importacion->id . '). Ya se puede procesar con la IA.';
        $this->esError = false;
    }

    /** Solo mientras nadie lo haya procesado: después ya hay productos colgando. */
    public function eliminar(int $id, SubirCatalogoParaImportarAction $accion): void
    {
        $importacion = ImportacionCatalogo::findOrFail($id);

        if (! in_array($importacion->estado, ['cargado', 'error'], true)) {
            $this->mensaje = 'Ese catálogo ya se procesó, así que no se puede quitar desde aquí.';
            $this->esError = true;

            return;
        }

        $accion->eliminar($importacion);

        $this->mensaje = 'Catálogo quitado.';
        $this->esError = false;
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administración</p>
        <h1 class="text-2xl font-bold text-slate-900">Catálogos para importar</h1>
        <p class="text-sm text-slate-500 mt-1">
            Sube el catálogo de la fábrica en PDF o imagen. Después la IA propone los productos y tú los revisas
            antes de que entren al catálogo.
        </p>
    </div>

    @if ($mensaje)
        <div class="rounded-lg border px-4 py-3 text-sm {{ $esError ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
            {{ $mensaje }}
        </div>
    @endif

    <form wire:submit="subir" class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6 space-y-4 max-w-2xl">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Línea</label>
                <select wire:model.live="linea_id" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="">Elige la línea</option>
                    @foreach ($this->lineas as $linea)
                        <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                    @endforeach
                </select>
                @error('linea_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Temporada <span class="text-slate-400 font-normal">(opcional)</span>
                </label>
                <select wire:model="campana_id" class="w-full rounded-lg border-slate-300 text-sm" @disabled(! $linea_id)>
                    <option value="">Se decide al aprobar</option>
                    @foreach ($this->temporadas as $temporada)
                        <option value="{{ $temporada->id }}">{{ $temporada->nombre }}</option>
                    @endforeach
                </select>
                @error('campana_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Archivo del catálogo</label>
            <input type="file" wire:model="archivo" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm">
            <p class="mt-1 text-xs text-slate-500">PDF, JPG o PNG. Hasta 40 MB.</p>
            @error('archivo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

            <div wire:loading wire:target="archivo" class="mt-2 text-xs text-slate-500">Subiendo el archivo…</div>
        </div>

        <button type="submit" wire:loading.attr="disabled"
            class="rounded-lg bg-fp-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-fp-primary/90">
            Subir catálogo
        </button>
    </form>

    <div class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Catálogos subidos</h2>

        @if ($this->importaciones->isEmpty())
            <p class="text-sm text-slate-500">Todavía no se ha subido ningún catálogo.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">#</th>
                        <th class="py-2">Línea</th>
                        <th class="py-2">Temporada</th>
                        <th class="py-2">Tipo</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2">Subido por</th>
                        <th class="py-2">Fecha</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->importaciones as $importacion)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $importacion->id }}</td>
                            <td class="py-2">{{ $importacion->linea?->nombre }}</td>
                            <td class="py-2">{{ $importacion->campana?->nombre ?? '—' }}</td>
                            <td class="py-2">{{ $importacion->tipo_archivo }}</td>
                            <td class="py-2">{{ str_replace('_', ' ', $importacion->estado) }}</td>
                            <td class="py-2">{{ $importacion->iniciadaPor?->nombreVisible() }}</td>
                            <td class="py-2">{{ $importacion->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('admin.catalogos.archivo', $importacion->id) }}"
                                    class="text-fp-primary text-xs font-medium mr-3">Ver archivo</a>
                                @if (in_array($importacion->estado, ['cargado', 'error'], true))
                                    <button type="button" wire:click="eliminar({{ $importacion->id }})"
                                        wire:confirm="¿Quitar este catálogo? Se borra el archivo."
                                        class="text-red-600 text-xs font-medium">Quitar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
