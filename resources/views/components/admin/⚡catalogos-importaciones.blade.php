<?php

use App\Jobs\ProcesarCatalogoConIa;
use App\Models\Campana;
use App\Models\ImportacionCatalogo;
use App\Models\Linea;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    /**
     * Para estimar lo que va a cobrar una lectura antes de arrancarla. Sale
     * del catalogo Cklass: 76 paginas costaron $0.98 USD. Es un numero para
     * avisar; el real se guarda en costo_usd cuando termina.
     */
    private const USD_POR_PAGINA = 0.013;

    public $archivo = null;
    public ?int $linea_id = null;
    public ?int $campana_id = null;

    public string $mensaje = '';
    public bool $esError = false;

    /** El catálogo cuyo «Procesar con IA» está esperando el modal. */
    #[Locked]
    public ?int $confirmarLecturaId = null;

    /** El catálogo cuyo «Quitar» está esperando el modal. */
    #[Locked]
    public ?int $confirmarQuitarId = null;

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
            ->withCount([
                'productosStaging',
                'productosStaging as productos_publicados_count' => function ($consulta) {
                    $consulta->where(function ($consulta) {
                        $consulta->whereNotNull('producto_creado_id')
                            ->orWhereNotNull('producto_campana_creado_id');
                    });
                },
            ])
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
            'archivo.mimes'     => 'Solo se aceptan archivos PDF.',
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

        // Se toma la importacion de forma atomica: con doble clic o dos
        // pestanas, solo una peticion gana y se encola UNA sola lectura (cada
        // una cuesta dinero y duplicaria los productos).
        $tomada = ImportacionCatalogo::where('id', $importacion->id)
            ->whereIn('estado', ['cargado', 'error'])
            ->update(['estado' => 'procesando', 'mensaje_error' => null, 'procesamiento_iniciado_at' => now()]);

        if ($tomada !== 1) {
            $this->mensaje = 'Ese catalogo ya se esta procesando o ya se proceso.';
            $this->esError = true;

            return;
        }

        // Si se reintenta, se limpia lo que habia quedado del intento anterior.
        $importacion->productosStaging()->delete();

        ProcesarCatalogoConIa::dispatch($importacion->id);

        $this->mensaje = 'Se mando a procesar. La IA va de '.config('ia.paginas_por_bloque')
            .' en '.config('ia.paginas_por_bloque').' paginas; el avance se actualiza solo.';
        $this->esError = false;
        $this->confirmarLecturaId = null;
    }

    /** Abre el modal antes de gastar. procesar() sigue siendo el que encola. */
    public function pedirProcesar(int $id): void
    {
        $importacion = ImportacionCatalogo::query()->findOrFail($id);

        if (! in_array($importacion->estado, ['cargado', 'error'], true)) {
            $this->mensaje = 'Ese catalogo ya se esta procesando o ya se proceso.';
            $this->esError = true;
            $this->confirmarLecturaId = null;

            return;
        }

        $this->confirmarQuitarId = null;
        $this->confirmarLecturaId = $importacion->id;
        $this->esError = false;
    }

    public function confirmarProcesar(): void
    {
        $id = $this->confirmarLecturaId;
        $this->confirmarLecturaId = null;

        if ($id === null) {
            return;
        }

        $this->procesar($id);
    }

    /** True si hay alguno trabajando: con eso la pantalla se refresca sola. */
    public function getHayTrabajoProperty(): bool
    {
        return ImportacionCatalogo::where('estado', 'procesando')->exists();
    }

    /**
     * Quita el catálogo subido. Los productos ya publicados se quedan.
     * eliminar() sigue siendo el que borra, para que una prueba pueda llamarlo.
     */
    public function eliminar(int $id, SubirCatalogoParaImportarAction $accion): void
    {
        $importacion = ImportacionCatalogo::findOrFail($id);
        $this->confirmarQuitarId = null;

        try {
            $resumen = $accion->eliminar($importacion);
        } catch (\RuntimeException $e) {
            $this->mensaje = $e->getMessage();
            $this->esError = true;

            return;
        }

        $this->mensaje = $this->mensajeTrasQuitar($resumen);
        $this->esError = false;
    }

    public function pedirQuitar(int $id): void
    {
        $importacion = ImportacionCatalogo::query()->findOrFail($id);

        if ($importacion->estado === 'procesando') {
            $this->mensaje = 'Ese catálogo se está leyendo. Espera a que termine para quitarlo.';
            $this->esError = true;
            $this->confirmarQuitarId = null;

            return;
        }

        $this->confirmarLecturaId = null;
        $this->confirmarQuitarId = $importacion->id;
        $this->esError = false;
    }

    public function confirmarQuitar(SubirCatalogoParaImportarAction $accion): void
    {
        $id = $this->confirmarQuitarId;

        if ($id === null) {
            return;
        }

        $this->eliminar($id, $accion);
    }

    public function cancelarConfirmacion(): void
    {
        $this->confirmarLecturaId = null;
        $this->confirmarQuitarId = null;
    }

    /** @param  array{propuestas: int, publicados: int}  $resumen */
    private function mensajeTrasQuitar(array $resumen): string
    {
        if ($resumen['publicados'] > 0) {
            $productos = $resumen['publicados'] === 1
                ? '1 producto ya publicado sigue'
                : $resumen['publicados'].' productos ya publicados siguen';

            return 'Catálogo quitado. '.$productos.' en el catálogo maestro.';
        }

        if ($resumen['propuestas'] > 0) {
            $propuestas = $resumen['propuestas'] === 1 ? '1 propuesta' : $resumen['propuestas'].' propuestas';

            return 'Catálogo quitado. Se borró el archivo y '.$propuestas.'. El catálogo maestro no se tocó.';
        }

        return 'Catálogo quitado. El catálogo maestro no se tocó.';
    }

    /**
     * El texto del modal de "Procesar" / "Reintentar".
     *
     * Son dos casos distintos: el primer Procesar no borra nada, y Reintentar
     * tira los productos ya leidos y vuelve a pagar la lectura completa. Al
     * terminar se borra el archivo del proveedor (el finally de
     * ExtraerProductosDelCatalogoAction) y archivo_ia_id queda en null, asi
     * que no hay segunda vuelta barata: cada clic vuelve a subir el PDF y a
     * reescribir el cache entero.
     *
     * No se usa costo_usd para el monto: el de una lectura a medias viene
     * vacio o en centavos y haria creer que reintentar sale baratisimo.
     *
     * El estimado solo existe si ya se leyo una vez, porque al subir no se
     * cuentan las paginas (eso pasa dentro de la lectura). En el primer
     * Procesar no hay numero que dar y no se inventa uno.
     */
    public function avisoAntesDeLeer(ImportacionCatalogo $importacion): string
    {
        $costo = $importacion->paginas
            ? 'Cuesta alrededor de $'.number_format($importacion->paginas * self::USD_POR_PAGINA, 2).' USD.'
            : 'El costo depende de cuántas páginas tenga; uno de 76 páginas ronda $1 USD.';

        if ($importacion->productos_staging_count > 0) {
            return 'Ya se leyeron '.$importacion->productos_staging_count.' productos de este catálogo, y eso ya se pagó. '
                .'Volver a leerlo los borra y cobra la lectura completa otra vez. '.$costo
                .' Si lo que quieres es revisar lo que ya se leyó, usa el botón Revisar.';
        }

        return 'Leer este catálogo con la IA cuesta dinero de verdad. '.$costo
            .' Se cobra aunque al final no apruebes ningún producto.';
    }

    /** Lo que dice el modal de Quitar, según si ya hay algo publicado. */
    public function avisoAntesDeQuitar(ImportacionCatalogo $importacion): string
    {
        $propuestas = (int) ($importacion->productos_staging_count
            ?? $importacion->productosStaging()->count());
        $publicados = (int) ($importacion->productos_publicados_count
            ?? $importacion->productosStaging()
                ->where(function ($consulta) {
                    $consulta->whereNotNull('producto_creado_id')
                        ->orWhereNotNull('producto_campana_creado_id');
                })
                ->count());

        if ($publicados > 0) {
            $quedan = $publicados === 1
                ? '1 producto que ya se publicó se queda'
                : $publicados.' productos que ya se publicaron se quedan';

            return 'Se quita el archivo y las propuestas de esta importación. '.$quedan
                .' en el catálogo maestro.';
        }

        if ($propuestas > 0) {
            $lista = $propuestas === 1 ? '1 propuesta' : $propuestas.' propuestas';

            return 'Se borran el archivo y '.$lista.' de esta importación. Nada de esto está en el catálogo maestro.';
        }

        return 'Se borra el archivo de este catálogo. El catálogo maestro no se toca.';
    }
};
?>

<div class="space-y-6">
    <x-panel.encabezado eyebrow="Administración" titulo="Catálogos para importar" nivel="h1"
        subtitulo="Sube el catálogo de la fábrica en PDF o imagen. Después la IA propone los productos y tú los revisas antes de que entren al catálogo." />

    @php($porEstado = $this->importaciones->countBy('estado'))
    <div class="fp-escalonado grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-metricas-admin>
        <x-panel.metrica titulo="Catálogos subidos" :valor="$this->importaciones->count()" icono="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" />
        <x-panel.metrica titulo="Leyendo con IA" :valor="$porEstado['procesando'] ?? 0" tono="primario" detalle="Se actualiza sola"
            icono="M9.75 3.1v5.7a2.25 2.25 0 01-.66 1.6L5 14.5M14.25 3.1v5.7c0 .6.24 1.17.66 1.6L19 14.5M5 14.5l-1.4 4.2A1 1 0 004.6 20h14.8a1 1 0 00.95-1.3L19 14.5M5 14.5h14" />
        <x-panel.metrica titulo="Por revisar" :valor="$porEstado['requiere_revision'] ?? 0" tono="aviso" icono="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.5 12C3.8 7.9 7.6 5 12 5s8.2 2.9 9.5 7c-1.3 4.1-5.1 7-9.5 7S3.8 16.1 2.5 12z" />
        <x-panel.metrica titulo="Aprobados" :valor="$porEstado['aprobado'] ?? 0" tono="exito" icono="M5 13l4 4L19 7" />
    </div>

    {{-- El gasto de la IA no se veia en ninguna pantalla: solo frenaba al
         pasarse del tope, asi que nadie sabia cuanto llevaba hasta chocar.
         Ojo con lo que NO es: el tope es de la app (IA_MAX_USD_MES), no el
         saldo de la cuenta de la IA. Puede quedar saldo y estar frenado, o
         haber margen de tope sin saldo. --}}
    @php($gastoDelMes = app(\App\Services\Ia\LimitesDeGastoIa::class)->gastoDelMes())
    @php($topeDelMes = (float) config('ia.max_usd_por_mes'))
    @php($cercaDelTope = $topeDelMes > 0 && $gastoDelMes >= $topeDelMes * 0.8)
    <div class="fp-tarjeta fp-aparecer p-4 sm:p-5" data-gasto-ia>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-700">Gasto de la IA este mes</h2>
            <p class="text-sm {{ $cercaDelTope ? 'font-semibold text-fp-danger' : 'text-slate-600' }}">
                ${{ number_format($gastoDelMes, 2) }} USD
                @if ($topeDelMes > 0)
                    de ${{ number_format($topeDelMes, 2) }} del tope de la app
                @else
                    (sin tope configurado)
                @endif
            </p>
        </div>

        @if ($topeDelMes > 0)
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full {{ $cercaDelTope ? 'bg-fp-danger' : 'bg-fp-primary' }}"
                    style="width: {{ min(100, (int) round($gastoDelMes / $topeDelMes * 100)) }}%"></div>
            </div>
        @endif

        <p class="mt-2 text-xs text-slate-500">
            Lo que costaron las lecturas de este mes; vuelve a cero el día 1. El tope es de la app
            (<code>IA_MAX_USD_MES</code>), no el saldo de la cuenta de la IA, y se revisa al empezar
            una lectura: la que ya arrancó no se detiene a la mitad.
        </p>
    </div>

    @if ($mensaje)
        <x-panel.alerta :tipo="$esError ? 'error' : 'exito'">{{ $mensaje }}</x-panel.alerta>
    @endif

    <form wire:submit="subir" class="fp-formulario fp-aparecer p-5 sm:p-6 space-y-4 max-w-2xl">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="fp-campo">
                <label for="catalogos-importaciones-linea-id" class="block text-sm font-medium text-slate-700 mb-1">Línea</label>
                <select id="catalogos-importaciones-linea-id" wire:model.live="linea_id" class="w-full rounded-lg text-sm">
                    <option value="">Elige la línea</option>
                    @foreach ($this->lineas as $linea)
                        <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                    @endforeach
                </select>
                @error('linea_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>

            <div class="fp-campo">
                <label for="catalogos-importaciones-campana-id" class="block text-sm font-medium text-slate-700 mb-1">
                    Temporada <span class="text-slate-400 font-normal">(opcional)</span>
                </label>
                <select id="catalogos-importaciones-campana-id" wire:model="campana_id" class="w-full rounded-lg text-sm" @disabled(! $linea_id)>
                    <option value="">Se decide al aprobar</option>
                    @foreach ($this->temporadas as $temporada)
                        <option value="{{ $temporada->id }}">{{ $temporada->nombre }}</option>
                    @endforeach
                </select>
                @error('campana_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="fp-campo">
            <label for="catalogos-importaciones-archivo" class="block text-sm font-medium text-slate-700 mb-1">Archivo del catálogo</label>
            <input id="catalogos-importaciones-archivo" type="file" wire:model="archivo" accept=".pdf,application/pdf" class="w-full text-sm">
            <p class="mt-1 text-xs text-slate-500">PDF. Hasta 40 MB.</p>
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
                                    <button type="button" wire:click="pedirProcesar({{ $importacion->id }})"
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
                                @if ($importacion->estado !== 'procesando')
                                    <button type="button" wire:click="pedirQuitar({{ $importacion->id }})"
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

    @php($lectura = $confirmarLecturaId ? $this->importaciones->firstWhere('id', $confirmarLecturaId) : null)
    @if ($lectura)
        <x-panel.modal
            :titulo="$lectura->estado === 'error' ? 'Reintentar con IA' : 'Procesar con IA'"
            ancho="max-w-md"
            cerrar="cancelarConfirmacion">
            <p class="text-sm text-slate-600">{{ $this->avisoAntesDeLeer($lectura) }}</p>
            <x-slot:pie>
                <button type="button" wire:click="cancelarConfirmacion" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="button" wire:click="confirmarProcesar" class="fp-btn fp-btn-primario">
                    {{ $lectura->estado === 'error' ? 'Reintentar' : 'Procesar' }}
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    @php($quitar = $confirmarQuitarId ? $this->importaciones->firstWhere('id', $confirmarQuitarId) : null)
    @if ($quitar)
        <x-panel.modal titulo="Quitar catálogo" ancho="max-w-md" cerrar="cancelarConfirmacion">
            <p class="text-sm text-slate-600">{{ $this->avisoAntesDeQuitar($quitar) }}</p>
            <x-slot:pie>
                <button type="button" wire:click="cancelarConfirmacion" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="button" wire:click="confirmarQuitar" class="fp-btn fp-btn-peligro">Quitar</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
