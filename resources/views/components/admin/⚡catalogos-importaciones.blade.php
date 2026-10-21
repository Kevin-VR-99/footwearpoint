<?php

use App\Jobs\ProcesarCatalogoConIa;
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
            ->withCount('productosStaging')
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


    /**
     * TG-238 (A8): manda a leer el catalogo con la IA.
     *
     * Va a la cola y no aqui: un catalogo de 122 paginas son trece llamadas a
     * la IA, varios minutos, y el servidor corta las peticiones web a los 30
     * segundos.
     */
    public function procesar(int $id): void
    {
        $importacion = ImportacionCatalogo::findOrFail($id);

        if (! in_array($importacion->estado, ['cargado', 'error'], true)) {
            $this->mensaje = 'Ese catalogo ya se esta procesando o ya se proceso.';
            $this->esError = true;

            return;
        }

        // Topes de gasto de la IA (config/ia.php): se avisa antes de gastar.
        $motivo = app(\App\Services\Ia\LimitesDeGastoIa::class)->motivoParaNoProcesar($importacion);

        if ($motivo !== null) {
            $this->mensaje = $motivo;
            $this->esError = true;

            return;
        }

        // Si se reintenta, se limpia lo que habia quedado del intento anterior.
        $importacion->productosStaging()->delete();
        $importacion->update(['estado' => 'procesando', 'mensaje_error' => null, 'procesamiento_iniciado_at' => now()]);

        ProcesarCatalogoConIa::dispatch($importacion->id);

        $this->mensaje = 'Se mando a procesar. La IA va de '.config('ia.paginas_por_bloque')
            .' en '.config('ia.paginas_por_bloque').' paginas; el avance se actualiza solo.';
        $this->esError = false;
    }

    /** True si hay alguno trabajando: con eso la pantalla se refresca sola. */
    public function getHayTrabajoProperty(): bool
    {
        return ImportacionCatalogo::where('estado', 'procesando')->exists();
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
    <x-panel.encabezado eyebrow="Administración" titulo="Catálogos para importar" nivel="h1"
        subtitulo="Sube el catálogo de la fábrica en PDF o imagen. Después la IA propone los productos y tú los revisas antes de que entren al catálogo." />

    @php($porEstado = $this->importaciones->countBy('estado'))
    <div class="fp-escalonado grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-metricas-admin>
        <x-panel.metrica titulo="Catálogos subidos" :valor="$this->importaciones->count()" icono="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" />
        <x-panel.metrica titulo="Leyendo con IA" :valor="$porEstado['procesando'] ?? 0" tono="primario" detalle="Se actualiza sola"
            icono="M9.75 3.1v5.7a2.25 2.25 0 01-.66 1.6L5 14.5M14.25 3.1v5.7c0 .6.24 1.17.66 1.6L19 14.5M5 14.5l-1.4 4.2A1 1 0 004.6 20h14.8a1 1 0 00.95-1.3L19 14.5M5 14.5h14" />
        <x-panel.metrica titulo="Por revisar" :valor="$porEstado['requiere_revision'] ?? 0" tono="aviso" icono="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.5 12C3.8 7.9 7.6 5 12 5s8.2 2.9 9.5 7c-1.3 4.1-5.1 7-9.5 7S3.8 16.1 2.5 12z" />
        <x-panel.metrica titulo="Aprobados" :valor="$porEstado['aprobado'] ?? 0" tono="exito" icono="M5 13l4 4L19 7" />
    </div>

    @if ($mensaje)
        <x-panel.alerta :tipo="$esError ? 'error' : 'exito'">{{ $mensaje }}</x-panel.alerta>
    @endif

    <form wire:submit="subir" class="fp-formulario fp-aparecer p-5 sm:p-6 space-y-4 max-w-2xl">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="fp-campo">
                <label class="block text-sm font-medium text-slate-700 mb-1">Línea</label>
                <select wire:model.live="linea_id" class="w-full rounded-lg text-sm">
                    <option value="">Elige la línea</option>
                    @foreach ($this->lineas as $linea)
                        <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                    @endforeach
                </select>
                @error('linea_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>

            <div class="fp-campo">
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Temporada <span class="text-slate-400 font-normal">(opcional)</span>
                </label>
                <select wire:model="campana_id" class="w-full rounded-lg text-sm" @disabled(! $linea_id)>
                    <option value="">Se decide al aprobar</option>
                    @foreach ($this->temporadas as $temporada)
                        <option value="{{ $temporada->id }}">{{ $temporada->nombre }}</option>
                    @endforeach
                </select>
                @error('campana_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="fp-campo">
            <label class="block text-sm font-medium text-slate-700 mb-1">Archivo del catálogo</label>
            <input type="file" wire:model="archivo" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm">
            <p class="mt-1 text-xs text-slate-500">PDF, JPG o PNG. Hasta 40 MB.</p>
            @error('archivo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror

            <div wire:loading wire:target="archivo" class="mt-2 text-xs text-slate-500">Subiendo el archivo…</div>
        </div>

        <button type="submit" wire:loading.attr="disabled"
            class="fp-btn fp-btn-primario">
            Subir catálogo
        </button>
    </form>

    {{-- Mientras la IA trabaja, la tabla se refresca sola para ver el avance.
         ".keep-alive": sin él, Livewire casi no consulta cuando la pestaña está
         en segundo plano (y el navegador además frena los temporizadores), así
         que la sesión podía caducar mientras la IA seguía leyendo. Cada
         consulta pasa por la sesión y la mantiene viva SOLO mientras hay un
         análisis en curso; al terminar se quita el poll y la sesión vuelve a
         caducar por inactividad como siempre. --}}
    <div class="fp-tarjeta fp-aparecer p-5 sm:p-6"
        @if ($this->hayTrabajo) wire:poll.5s.keep-alive data-mantiene-sesion @endif>
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Catálogos subidos</h2>

        @if ($this->hayTrabajo)
            <p class="mb-3 flex items-start gap-2 rounded-xl bg-fp-badge-info-bg px-3 py-2 text-sm text-fp-badge-info-fg"><span class="fp-insignia-viva mt-1.5 inline-flex"><span class="fp-insignia-punto" aria-hidden="true"></span></span><span>
                La IA está leyendo un catálogo. Va de {{ config('ia.paginas_por_bloque') }} en
                {{ config('ia.paginas_por_bloque') }} páginas y puede tardar varios minutos; esta página se actualiza sola.
            </span></p>
        @endif

        @if ($this->importaciones->isEmpty())
            <x-panel.vacio mensaje="Todavía no se ha subido ningún catálogo." icono="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" />
        @else
            <div class="fp-tabla -mx-5 overflow-x-auto sm:-mx-6"><table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-3 font-semibold">#</th>
                        <th class="px-4 py-3 font-semibold">Línea</th>
                        <th class="px-4 py-3 font-semibold">Temporada</th>
                        <th class="px-4 py-3 font-semibold">Estado</th>
                        <th class="px-4 py-3 font-semibold">Páginas</th>
                        <th class="px-4 py-3 font-semibold">Productos</th>
                        <th class="px-4 py-3 font-semibold">Costo</th>
                        <th class="px-4 py-3 font-semibold">Subido por</th>
                        <th class="px-4 py-3 font-semibold"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->importaciones as $importacion)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ $importacion->id }}</td>
                            <td class="px-4 py-3">{{ $importacion->linea?->nombre }}</td>
                            <td class="px-4 py-3">{{ $importacion->campana?->nombre ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <x-panel.insignia :variante="match ($importacion->estado) {
                                        'procesando' => 'info',
                                        'requiere_revision' => 'warning',
                                        'aprobado' => 'success',
                                        'error', 'rechazado' => 'danger',
                                        default => 'neutral',
                                    }" :viva="$importacion->estado === 'procesando'" punto>{{ str_replace('_', ' ', $importacion->estado) }}</x-panel.insignia>
                                @if ($importacion->mensaje_error)
                                    <p class="mt-1 text-xs text-fp-danger">{{ $importacion->mensaje_error }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $importacion->paginas ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $importacion->productos_staging_count ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $importacion->costo_usd ? '$'.number_format((float) $importacion->costo_usd, 2).' USD' : '—' }}</td>
                            <td class="px-4 py-3">{{ $importacion->iniciadaPor?->nombreVisible() }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if (in_array($importacion->estado, ['cargado', 'error'], true))
                                    <button type="button" wire:click="procesar({{ $importacion->id }})"
                                        wire:loading.attr="disabled"
                                        class="fp-accion fp-accion-primaria">
                                        {{ $importacion->estado === 'error' ? 'Reintentar con IA' : 'Procesar con IA' }}
                                    </button>
                                @endif
                                @if ($importacion->productos_staging_count > 0)
                                    <a href="{{ route('admin.catalogos.revisar', $importacion->id) }}"
                                        class="fp-accion fp-accion-primaria">Revisar</a>
                                @endif
                                <button type="button" data-ver-archivo
                                    x-on:click="$dispatch('abrir-visor', @js(['url' => route('admin.catalogos.archivo', $importacion->id), 'tipo' => $importacion->tipo_archivo, 'nombre' => 'Catálogo #'.$importacion->id]))"
                                    class="fp-accion">Ver archivo</button>
                                @if (in_array($importacion->estado, ['cargado', 'error'], true))
                                    <button type="button" wire:click="eliminar({{ $importacion->id }})"
                                        wire:confirm="¿Quitar este catálogo? Se borra el archivo."
                                        class="fp-accion fp-accion-peligro">Quitar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <x-ui.visor-archivo />
</div>
