<?php

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Services\Ia\CambiarEstadoProductoImportadoAction;
use App\Services\Ia\CamposDelProductoImportado;
use App\Services\Ia\CompletarCampoEnLoteAction;
use App\Models\Campana;
use App\Services\Catalogo\CampanaDeTemporada;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\Marca;
use App\Services\Ia\AprobarProductosImportadosAction;
use App\Services\Ia\ColoresDelCatalogo;
use App\Services\Ia\CorregirProductoImportadoAction;
use App\Services\Ia\ProponerRecortesDeFotosAction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * TG-239 (A9 / E5-03) — Revisar y corregir lo que propuso la IA.
 *
 * Esta pantalla es la razón por la que el sistema se puede defender: la IA
 * propone, una persona decide. Nada de lo que está aquí se publica solo; eso
 * es A10, y es otro botón y otra tarjeta.
 *
 * Está armada sobre lo que salió del catálogo real (Confort, 76 páginas):
 * 154 productos y 133 con algún campo dudoso. Con esos números, una lista
 * plana no sirve de nada, así que por omisión se abre en "por revisar" y lo
 * dudoso va resaltado. Y como la marca salió dudosa 116 veces, hay un relleno
 * en lote: lo que si no serían 116 clics para escribir siempre lo mismo.
 */
new #[Layout('layouts.admin')] #[Title('Revisar catálogo — Admin')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $importacionId;

    #[Url]
    public string $filtro = 'por_revisar';

    /** La fila que se está corrigiendo, y sus campos. */
    #[Locked]
    public ?int $editandoId = null;

    /** @var array<string, mixed> */
    public array $campos = [];

    public string $campoEnLote = 'marca';
    public string $valorEnLote = '';

    public string $mensaje = '';
    public bool $esError = false;

    /* TG-240 (A10): lo que hace falta para pasar al catalogo maestro. */
    /** @var array<string, string> nombre comercial del color => id del color base */
    public array $coloresBase = [];

    public ?int $campanaId = null;
    public ?int $categoriaPorOmision = null;
    public ?int $marcaPorOmision = null;

    /** @var array<string, mixed>|null Lo que paso en la ultima aprobacion. */
    public ?array $resumenAprobacion = null;

    public function mount(int $importacion): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        setPermissionsTeamId(0);

        if (! Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }

        $this->importacionId = $importacion;

        // Que no exista responde 404, igual que en el resto del sistema.
        $fila = ImportacionCatalogo::findOrFail($importacion);

        // Por omisión, la temporada actual de la línea del catálogo.
        $this->campanaId = $fila->campana_id
            ?? $this->temporadas->first(fn (Campana $c) => $c->temporada?->esActual())?->id;

        foreach (app(ColoresDelCatalogo::class)->distintos($fila) as $comercial => $info) {
            $this->coloresBase[$comercial] = (string) ($info['asignado'] ?? $info['sugerido'] ?? '');
        }
    }

    public function getImportacionProperty(): ImportacionCatalogo
    {
        return ImportacionCatalogo::with(['linea', 'campana'])->findOrFail($this->importacionId);
    }

    public function getProductosProperty()
    {
        $consulta = ProductoImportadoStaging::where('importacion_id', $this->importacionId);

        $consulta = match ($this->filtro) {
            'descartados' => $consulta->where('estado', 'descartado'),
            'todos'       => $consulta->where('estado', '!=', 'descartado'),
            // Por omisión: lo que todavía nadie ha mirado o la IA dejó dudoso.
            default       => $consulta->where('estado', 'pendiente'),
        };

        return $consulta->orderByRaw('CAST(JSON_EXTRACT(datos_extraidos, "$.pagina") AS UNSIGNED)')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, int> */
    public function getResumenProperty(): array
    {
        $filas = ProductoImportadoStaging::where('importacion_id', $this->importacionId)
            ->get(['estado', 'campos_dudosos']);

        return [
            'total'       => $filas->count(),
            'por_revisar' => $filas->where('estado', 'pendiente')->count(),
            'corregidos'  => $filas->where('estado', 'corregido')->count(),
            'descartados' => $filas->where('estado', 'descartado')->count(),
            'dudosos'     => $filas->filter(fn ($f) => count($f->campos_dudosos ?? []) > 0)->count(),
        ];
    }

    public function cambiarFiltro(string $filtro): void
    {
        $this->filtro = $filtro;
        $this->editandoId = null;
    }

    public function editar(int $id): void
    {
        $fila = $this->fila($id);

        $this->editandoId = $id;
        $this->campos = [];

        foreach (CamposDelProductoImportado::todos() as $campo) {
            $valor = $fila->datos_extraidos[$campo] ?? null;
            $this->campos[$campo] = is_bool($valor) ? ($valor ? '1' : '0') : (string) ($valor ?? '');
        }
    }

    public function cancelar(): void
    {
        $this->editandoId = null;
        $this->campos = [];
    }

    public function guardar(CorregirProductoImportadoAction $accion): void
    {
        $this->validate([
            'campos.pagina'          => ['required', 'integer', 'min:1'],
            'campos.precio_publico'  => ['nullable', 'numeric', 'min:0'],
            'campos.codigo_catalogo' => ['nullable', 'string', 'max:120'],
            'campos.marca'           => ['nullable', 'string', 'max:120'],
            'campos.modelo'          => ['nullable', 'string', 'max:120'],
            'campos.nombre'          => ['nullable', 'string', 'max:190'],
            'campos.color'           => ['nullable', 'string', 'max:120'],
            'campos.talla_desde'     => ['nullable', 'string', 'max:20'],
            'campos.talla_hasta'     => ['nullable', 'string', 'max:20'],
            'campos.disponibilidad'  => ['nullable', 'string', 'max:190'],
        ], [
            'campos.pagina.required' => 'Pon en qué página viene el producto.',
            'campos.pagina.integer'  => 'La página es un número.',
            'campos.precio_publico.numeric' => 'El precio es un número, sin el signo de pesos.',
        ]);

        $accion->ejecutar($this->fila($this->editandoId), $this->campos);

        $this->editandoId = null;
        $this->campos = [];
        $this->mensaje = 'Producto corregido.';
        $this->esError = false;
    }

    public function descartar(int $id, CambiarEstadoProductoImportadoAction $accion): void
    {
        $accion->descartar($this->fila($id));

        $this->editandoId = null;
        $this->mensaje = 'Producto descartado. Puedes recuperarlo desde «Descartados».';
        $this->esError = false;
    }

    /**
     * TG-282 — Dar por revisados los que la IA leyó sin dudar.
     *
     * Aprobar solo toma las filas en "corregido", y hasta ahora solo llegaban
     * ahí de dos formas: abriendo "Corregir" una por una, o de rebote cuando
     * el relleno en lote las tocaba. Con el Cklass salió gratis porque ningún
     * producto traía marca, así que el relleno marcó los 166. Con el Impuls
     * las marcas sí vienen impresas, así que no marcó ninguno y quedaban 23
     * para abrir de a uno.
     *
     * Esto NO aprueba nada ni afloja la revisión: solo deja de exigir que una
     * persona abra y guarde un producto en el que la IA no marcó ni una duda.
     * Lo que tiene algo dudoso se queda esperando, que es donde sí hace falta
     * mirar.
     */
    public function darPorRevisadosLosLimpios(CorregirProductoImportadoAction $accion): void
    {
        $limpios = $this->importacion->productosStaging()
            ->where('estado', 'pendiente')
            ->get()
            ->filter(fn (ProductoImportadoStaging $fila) => ($fila->campos_dudosos ?? []) === []);

        if ($limpios->isEmpty()) {
            $this->mensaje = 'No hay productos sin dudas esperando: los que quedan hay que mirarlos uno por uno.';
            $this->esError = false;

            return;
        }

        foreach ($limpios as $fila) {
            $fila->update(['estado' => 'corregido']);
            $accion->anotarQuienRevisa($fila);
        }

        $this->mensaje = $limpios->count().' producto(s) sin dudas quedaron listos para aprobar. '
            .'Los que tienen algún campo dudoso siguen esperando tu revisión.';
        $this->esError = false;
    }

    public function getSinDudasPendientesProperty(): int
    {
        return $this->importacion->productosStaging()
            ->where('estado', 'pendiente')
            ->get()
            ->filter(fn (ProductoImportadoStaging $fila) => ($fila->campos_dudosos ?? []) === [])
            ->count();
    }

    public function recuperar(int $id, CambiarEstadoProductoImportadoAction $accion): void
    {
        $accion->recuperar($this->fila($id));

        $this->mensaje = 'Producto recuperado; queda por revisar otra vez.';
        $this->esError = false;
    }

    /*
     * TG-282 (E5-05) — La foto que propuso la IA.
     *
     * Tres salidas y las tres dejan aprobar: quedarse con el recorte, subir
     * otra foto, o dejarlo sin foto. Lo que NO pasa es que una propuesta sin
     * mirar se publique sola: una foto del zapato de al lado es peor que el
     * hueco vacío, y el hueco se llena después desde el catálogo maestro.
     */

    /** La foto de la fila que se está mirando de cerca. */
    public $fotoNueva = null;

    #[Locked]
    public ?int $subiendoFotoDe = null;

    public function confirmarFoto(int $id): void
    {
        $fila = $this->fila($id);

        if (! $fila->foto_propuesta_url) {
            return;
        }

        $fila->update(['foto_estado' => 'confirmada']);

        $this->mensaje = 'Foto confirmada. Entrará al catálogo cuando apruebes el producto.';
        $this->esError = false;
    }

    public function descartarFoto(int $id): void
    {
        $this->fila($id)->update(['foto_estado' => 'descartada']);

        $this->mensaje = 'El producto se aprobará sin foto. Puedes subirla después desde el catálogo maestro.';
        $this->esError = false;
    }

    public function pedirFoto(int $id): void
    {
        // Que la fila exista y sea de esta importación antes de abrir nada.
        $this->fila($id);

        $this->subiendoFotoDe = $id;
        $this->fotoNueva = null;
        $this->resetValidation();
    }

    public function cancelarFoto(): void
    {
        $this->subiendoFotoDe = null;
        $this->fotoNueva = null;
        $this->resetValidation();
    }

    /** Las mismas reglas que el resto de las fotos de producto. */
    public function subirFoto(): void
    {
        $this->validate([
            'fotoNueva' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'fotoNueva.required' => 'Elige una foto.',
            'fotoNueva.mimes'    => 'La foto tiene que ser jpg o png.',
            'fotoNueva.max'      => 'La foto no puede pasar de 5 MB.',
        ]);

        $fila = $this->fila($this->subiendoFotoDe);

        $ruta = Storage::disk('s3')->putFile(
            ProponerRecortesDeFotosAction::CARPETA,
            $this->fotoNueva
        );

        if ($ruta === false) {
            $this->mensaje = 'No se pudo guardar la foto. Inténtalo otra vez.';
            $this->esError = true;

            return;
        }

        // Queda confirmada de una vez: alguien la eligió a mano, no hay nada
        // más que revisar.
        $fila->update([
            'foto_propuesta_url' => Storage::disk('s3')->url($ruta),
            'foto_estado'        => 'confirmada',
        ]);

        $this->subiendoFotoDe = null;
        $this->fotoNueva = null;
        $this->mensaje = 'Foto guardada. Entrará al catálogo cuando apruebes el producto.';
        $this->esError = false;
    }

    public function completarEnLote(CompletarCampoEnLoteAction $accion): void
    {
        $this->validate([
            'valorEnLote' => ['required', 'string', 'max:190'],
        ], [
            'valorEnLote.required' => 'Escribe el valor que quieres poner.',
        ]);

        $cuantos = $accion->ejecutar($this->importacion, $this->campoEnLote, $this->valorEnLote);

        $nombre = CamposDelProductoImportado::nombreDe($this->campoEnLote);

        $this->valorEnLote = '';
        $this->mensaje = $cuantos === 0
            ? 'Ninguno tenía ese campo vacío, así que no se cambió nada.'
            : "Se puso «{$nombre}» en {$cuantos} ".($cuantos === 1 ? 'producto' : 'productos').' que lo tenían vacío.';
        $this->esError = false;
    }


    // ------------------------------------------------------------------
    // TG-240 (A10): pasar al catalogo maestro
    // ------------------------------------------------------------------

    /** @return array<string, array{productos: int, asignado: ?int, sugerido: ?int, origen: string}> */
    public function getColoresProperty(): array
    {
        return app(ColoresDelCatalogo::class)->distintos($this->importacion);
    }

    public function getPaletaProperty()
    {
        return Color::where('activo', true)->orderBy('nombre')->get();
    }

    public function getCategoriasProperty()
    {
        return CategoriaProducto::where('activa', true)->orderBy('nombre')->get();
    }

    public function getMarcasProperty()
    {
        return Marca::where('activa', true)->orderBy('nombre')->get();
    }

    /**
     * La temporada actual y la siguiente (globales), ya como la campaña de la
     * línea del catálogo; si la importación venía de antes con otra, también.
     */
    public function getTemporadasProperty()
    {
        $servicio = app(CampanaDeTemporada::class);
        $lineaId = (int) $this->importacion->linea_id;
        $campanas = $servicio->elegibles()->map(fn ($t) => $servicio->paraLinea($lineaId, $t)->setRelation('temporada', $t));

        if ($this->importacion->campana_id && ! $campanas->contains('id', $this->importacion->campana_id)) {
            $campanas->push(Campana::with('temporada')->find($this->importacion->campana_id));
        }

        return $campanas->filter()->values();
    }

    public function getListosParaAprobarProperty(): int
    {
        return $this->importacion->productosStaging()->where('estado', 'corregido')->count();
    }

    public function getColoresSinResolverProperty(): int
    {
        return app(ColoresDelCatalogo::class)->sinResolver($this->importacion);
    }

    public function guardarColores(ColoresDelCatalogo $accion): void
    {
        $resueltos = $accion->asignar($this->importacion, $this->coloresBase);

        $this->mensaje = $resueltos === 0
            ? 'No se guardó ningún color. Elige al menos uno de la paleta.'
            : "Colores guardados en {$resueltos} ".($resueltos === 1 ? 'producto' : 'productos').'.';
        $this->esError = $resueltos === 0;
    }

    public function aprobar(AprobarProductosImportadosAction $accion): void
    {
        $this->validate([
            'campanaId'           => ['required', 'integer', function ($atributo, $valor, $falla) {
                $campana = Campana::with('temporada')->find($valor);
                if (! $campana || (int) $campana->linea_id !== (int) $this->importacion->linea_id) {
                    $falla('Esa temporada no es de la línea del catálogo.');
                } elseif (! app(CampanaDeTemporada::class)->esElegible($campana->temporada_id)) {
                    $falla('Solo se puede pasar a la temporada actual o a la siguiente.');
                }
            }],
            'categoriaPorOmision' => ['required', 'integer', 'exists:categorias_producto,id'],
            'marcaPorOmision'     => ['required', 'integer', 'exists:marcas,id'],
        ], [
            'campanaId.required'           => 'Elige a qué temporada entran.',
            'categoriaPorOmision.required' => 'Elige la categoría por omisión.',
            // La marca es obligatoria porque productos tiene único (marca, modelo)
            // y en estos catálogos viene vacía muchas veces.
            'marcaPorOmision.required'     => 'Elige la marca por omisión: muchos productos no la traen.',
        ]);

        if ($this->listosParaAprobar === 0) {
            $this->mensaje = 'No hay nada revisado todavía. Revisa los productos antes de aprobarlos.';
            $this->esError = true;

            return;
        }

        if ($this->coloresSinResolver > 0) {
            $this->mensaje = 'Faltan colores por resolver. Guarda primero a qué color de la paleta corresponde cada uno.';
            $this->esError = true;

            return;
        }

        $this->resumenAprobacion = $accion->ejecutar(
            $this->importacion,
            (int) $this->campanaId,
            (int) $this->categoriaPorOmision,
            (int) $this->marcaPorOmision,
        );

        $this->editandoId = null;
        $this->mensaje = 'Listo. Abajo está el detalle de lo que entró al catálogo.';
        $this->esError = false;
    }

    /** Siempre dentro de ESTA importación: un id de otra no se encuentra. */
    private function fila(?int $id): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::where('importacion_id', $this->importacionId)
            ->findOrFail($id);
    }

    public function with(): array
    {
        return ['nombresDeCampos' => CamposDelProductoImportado::NOMBRES];
    }
};
?>

<div class="space-y-6">
    <x-panel.encabezado
        eyebrow="Importación con IA"
        titulo="Revisar catálogo #{{ $this->importacion->id }}"
        :volver="route('admin.catalogos')"
        volver-texto="← Volver a catálogos">
        <p class="mt-1 text-sm text-fp-text-muted">
            {{ $this->importacion->linea?->nombre }}
            @if ($this->importacion->campana) · {{ $this->importacion->campana->nombre }} @endif
            · {{ $this->importacion->paginas }} páginas
        </p>
        <x-slot:acciones>
            <x-panel.boton variante="secundario" tamano="sm" type="button" data-ver-archivo
                x-on:click="$dispatch('abrir-visor', {{ \Illuminate\Support\Js::from(['url' => route('admin.catalogos.archivo', $this->importacion->id), 'tipo' => $this->importacion->tipo_archivo, 'nombre' => 'Catálogo #'.$this->importacion->id]) }})">
                Ver el catálogo
            </x-panel.boton>
            <x-ui.visor-archivo />
        </x-slot:acciones>
    </x-panel.encabezado>

    {{-- La IA propone, una persona decide. Que se lea en la pantalla. --}}
    <x-panel.alerta tipo="info">
        Esto es lo que <strong>propuso</strong> la IA. Nada entra al catálogo hasta que lo revises aquí.
        Lo resaltado en ámbar es lo que ella misma marcó como dudoso.
    </x-panel.alerta>

    @if ($mensaje)
        <x-panel.alerta :tipo="$esError ? 'error' : 'exito'">{{ $mensaje }}</x-panel.alerta>
    @endif

    {{-- Resumen --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            'total' => 'Propuestos',
            'por_revisar' => 'Por revisar',
            'corregidos' => 'Revisados',
            'descartados' => 'Descartados',
        ] as $clave => $titulo)
            <x-panel.tarjeta>
                <p class="text-xs uppercase tracking-wide text-fp-text-muted">{{ $titulo }}</p>
                <p class="mt-1 text-2xl font-bold text-fp-sidebar">{{ $this->resumen[$clave] }}</p>
            </x-panel.tarjeta>
        @endforeach
    </div>

    {{-- Relleno en lote: nace de que la marca salio dudosa 116 de 154 veces. --}}
    <x-panel.tarjeta titulo="Completar lo que falta, de una vez">
        <p class="mb-3 text-sm text-fp-text-muted">
            Hay catálogos que no traen impreso un dato en ninguna página; el de Confort, por ejemplo, no trae la
            marca, porque la marca es la línea. Esto lo pone en todos los que lo tengan vacío.
            <strong>No pisa lo que la IA sí leyó.</strong>
        </p>
        <form wire:submit="completarEnLote" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="revisar-catalogo-campoEnLote" class="mb-1 block text-sm font-medium">Campo</label>
                <select id="revisar-catalogo-campoEnLote" wire:model.live="campoEnLote" class="rounded-lg text-sm">
                    @foreach (\App\Services\Ia\CamposDelProductoImportado::RELLENABLES_EN_LOTE as $campo)
                        <option value="{{ $campo }}">{{ $nombresDeCampos[$campo] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[14rem] flex-1">
                <label for="revisar-catalogo-valorEnLote" class="mb-1 block text-sm font-medium">Valor</label>
                {{-- En un campo de sí o no, una caja de texto invita a escribir
                     "no" y eso se guardaría como sí. --}}
                @if (\App\Services\Ia\CamposDelProductoImportado::esBooleano($campoEnLote))
                    <select id="revisar-catalogo-valorEnLote" wire:model="valorEnLote" class="w-full rounded-lg text-sm">
                        <option value="">Elige</option>
                        <option value="si">Sí, hay medias tallas</option>
                        <option value="no">No, solo enteros</option>
                    </select>
                @else
                    <input id="revisar-catalogo-valorEnLote" type="text" wire:model="valorEnLote" placeholder="Por ejemplo: 8 Days"
                        class="w-full rounded-lg text-sm">
                @endif
                @error('valorEnLote') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
            </div>
            <x-panel.boton type="submit" tamano="md" wire:loading.attr="disabled">Completar</x-panel.boton>
        </form>

        {{-- TG-282: lo que la IA leyó sin dudar no necesita que alguien abra
             y guarde el formulario para poder aprobarse. --}}
        @if ($this->sinDudasPendientes > 0)
            <div class="mt-4 border-t border-slate-100 pt-4">
                <p class="mb-2 text-sm text-fp-text-muted">
                    Hay <strong>{{ $this->sinDudasPendientes }}</strong> producto(s) en los que la IA no marcó
                    ninguna duda. Puedes darlos por revisados de una vez; los que sí tienen algo dudoso
                    seguirán esperando.
                </p>
                <x-panel.boton type="button" tamano="md" wire:click="darPorRevisadosLosLimpios"
                    wire:loading.attr="disabled" data-dar-por-revisados>
                    Dar por revisados los {{ $this->sinDudasPendientes }} sin dudas
                </x-panel.boton>
            </div>
        @endif
    </x-panel.tarjeta>

    {{-- TG-240 (A10): los colores se resuelven UNA VEZ POR COLOR, no por producto. --}}
    @if ($this->colores)
        <x-panel.tarjeta titulo="Colores de este catálogo">
            <p class="mb-3 text-sm text-fp-text-muted">
                El catálogo dice «MIEL» y la paleta tiene «Café». Di una sola vez a qué color de la paleta
                corresponde cada uno: el nombre del catálogo se guarda igual, y la paleta sirve para agrupar
                y filtrar. En la siguiente importación ya no te lo va a preguntar.
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->colores as $comercial => $info)
                    <div class="rounded-xl border border-slate-200 p-3">
                        <p class="text-sm font-semibold text-fp-sidebar">{{ $comercial }}</p>
                        <p class="mb-2 text-xs text-fp-text-muted">
                            {{ $info['productos'] }} {{ $info['productos'] === 1 ? 'producto' : 'productos' }}
                            · {{ $info['origen'] }}
                        </p>
                        <select wire:model="coloresBase.{{ $comercial }}" class="w-full rounded-lg text-sm">
                            <option value="">Elige un color</option>
                            @foreach ($this->paleta as $color)
                                <option value="{{ $color->id }}">{{ $color->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-panel.boton wire:click="guardarColores" wire:loading.attr="disabled">Guardar colores</x-panel.boton>
                @if ($this->coloresSinResolver > 0)
                    <span class="text-xs text-fp-badge-warning-fg">
                        {{ $this->coloresSinResolver }} productos todavía sin color resuelto.
                    </span>
                @endif
            </div>
        </x-panel.tarjeta>
    @endif

    {{-- TG-240 (A10): el paso final. --}}
    <x-panel.tarjeta titulo="Pasar al catálogo maestro">
        <p class="mb-3 text-sm text-fp-text-muted">
            Solo entran los productos que <strong>ya revisaste</strong> ({{ $this->listosParaAprobar }}).
            Los que siguen en «por revisar» se quedan esperando.
        </p>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="revisar-catalogo-campanaId" class="mb-1 block text-sm font-medium">Temporada</label>
                <select id="revisar-catalogo-campanaId" wire:model="campanaId" class="w-full rounded-lg text-sm">
                    <option value="">Elige la temporada</option>
                    @foreach ($this->temporadas as $temporada)
                        <option value="{{ $temporada->id }}">{{ $temporada->temporada?->nombre ?? $temporada->nombre }}{{ $temporada->temporada?->esActual() ? ' (actual)' : ($temporada->temporada?->esFutura() ? ' (próxima, oculta hasta el '.$temporada->temporada->fecha_inicio->format('d/m/Y').')' : '') }}</option>
                    @endforeach
                </select>
                @error('campanaId') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="revisar-catalogo-categoriaPorOmision" class="mb-1 block text-sm font-medium">Categoría por omisión</label>
                <select id="revisar-catalogo-categoriaPorOmision" wire:model="categoriaPorOmision" class="w-full rounded-lg text-sm">
                    <option value="">Elige la categoría</option>
                    @foreach ($this->categorias as $categoria)
                        <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-fp-text-muted">Se puede cambiar producto por producto en «Corregir».</p>
                @error('categoriaPorOmision') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="revisar-catalogo-marcaPorOmision" class="mb-1 block text-sm font-medium">Marca por omisión</label>
                <select id="revisar-catalogo-marcaPorOmision" wire:model="marcaPorOmision" class="w-full rounded-lg text-sm">
                    <option value="">Elige la marca</option>
                    @foreach ($this->marcas as $marca)
                        <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-fp-text-muted">Para los que no la traigan impresa.</p>
                @error('marcaPorOmision') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-4">
            <x-panel.boton wire:click="aprobar" wire:loading.attr="disabled"
                wire:confirm="¿Pasar los productos revisados al catálogo maestro?">
                Aprobar los revisados ({{ $this->listosParaAprobar }})
            </x-panel.boton>
        </div>

        @if ($resumenAprobacion)
            <div class="mt-4 rounded-xl border border-slate-200 bg-fp-page p-4 text-sm">
                <p class="font-semibold text-fp-sidebar">Lo que entró al catálogo</p>
                <ul class="mt-2 space-y-1 text-fp-text-muted">
                    <li>Productos aprobados: <strong>{{ $resumenAprobacion['aprobados'] }}</strong></li>
                    <li>Nuevos: {{ $resumenAprobacion['productos_nuevos'] }} · ya existían y se reutilizaron:
                        {{ $resumenAprobacion['productos_reusados'] }}</li>
                    <li>Variantes (tallas) creadas: {{ $resumenAprobacion['variantes'] }}</li>
                    @if ($resumenAprobacion['tallas_creadas'])
                        <li>Tallas nuevas en el catálogo:
                            <strong>{{ implode(', ', array_keys($resumenAprobacion['tallas_creadas'])) }}</strong></li>
                    @endif
                    @if ($resumenAprobacion['pendientes'])
                        <li>Se quedaron sin aprobar por no estar revisados: {{ $resumenAprobacion['pendientes'] }}</li>
                    @endif
                </ul>

                {{-- TG-282: el mismo modelo en otro color SÍ entró, pero no
                     creó una publicación nueva. Va aparte de los saltados
                     para que no parezca que se perdió. --}}
                @if (($resumenAprobacion['colores_agregados'] ?? []) !== [])
                    <p class="mt-3 font-semibold text-slate-700">
                        Colores agregados a un producto que ya estaba ({{ count($resumenAprobacion['colores_agregados']) }}):
                    </p>
                    <ul class="mt-1 space-y-1 text-xs text-fp-text-muted">
                        @foreach ($resumenAprobacion['colores_agregados'] as $nota)
                            <li>· {{ $nota }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($resumenAprobacion['saltados'])
                    <p class="mt-3 font-semibold text-fp-badge-warning-fg">
                        Saltados ({{ count($resumenAprobacion['saltados']) }}):
                    </p>
                    <ul class="mt-1 space-y-1 text-xs text-fp-text-muted">
                        @foreach ($resumenAprobacion['saltados'] as $motivo)
                            <li>· {{ $motivo }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-panel.tarjeta>

    {{-- Filtros --}}
    <div class="fp-pestanas" role="tablist" aria-label="Filtrar productos">
        @foreach ([
            'por_revisar' => 'Por revisar ('.$this->resumen['por_revisar'].')',
            'todos' => 'Todos ('.($this->resumen['total'] - $this->resumen['descartados']).')',
            'descartados' => 'Descartados ('.$this->resumen['descartados'].')',
        ] as $clave => $titulo)
            <button type="button" role="tab" wire:click="cambiarFiltro('{{ $clave }}')"
                aria-selected="{{ $filtro === $clave ? 'true' : 'false' }}" class="fp-pestana text-xs">{{ $titulo }}</button>
        @endforeach
    </div>

    <x-panel.tabla>
        <x-slot:encabezado>
            <tr>
                <th class="px-4 py-3">Pág.</th>
                <th class="px-4 py-3">Código</th>
                <th class="px-4 py-3">Marca</th>
                <th class="px-4 py-3">Color</th>
                <th class="px-4 py-3">Tallas</th>
                <th class="px-4 py-3">Precio</th>
                <th class="px-4 py-3">Dudoso</th>
                <th class="px-4 py-3">Foto</th>
                <th class="px-4 py-3"></th>
            </tr>
        </x-slot:encabezado>

        @forelse ($this->productos as $producto)
            @php
                $datos = $producto->datos_extraidos ?? [];
                $dudosos = $producto->campos_dudosos ?? [];
            @endphp

            <tr class="align-top">
                @foreach (['pagina', 'codigo_catalogo', 'marca', 'color'] as $campo)
                    <td @class([
                        'px-4 py-3',
                        'bg-fp-badge-warning-bg text-fp-badge-warning-fg font-medium' => in_array($campo, $dudosos, true),
                    ])
                        @if (in_array($campo, $dudosos, true)) title="La IA no estaba segura de este dato" @endif>
                        {{ $datos[$campo] ?? '—' }}
                    </td>
                @endforeach

                <td class="px-4 py-3">
                    {{ $datos['talla_desde'] ?? '?' }} – {{ $datos['talla_hasta'] ?? '?' }}
                    @if (($datos['medios_numeros'] ?? null) === true)
                        <span class="text-xs text-fp-text-muted">(con medias)</span>
                    @endif
                </td>

                <td @class([
                    'px-4 py-3',
                    'bg-fp-badge-warning-bg text-fp-badge-warning-fg font-medium' => in_array('precio_publico', $dudosos, true),
                ])>
                    {{ isset($datos['precio_publico']) ? '$'.number_format((float) $datos['precio_publico'], 2) : '—' }}
                </td>

                <td class="px-4 py-3">
                    @if ($dudosos)
                        <x-panel.insignia variante="warning" punto>{{ count($dudosos) }}</x-panel.insignia>
                        <p class="mt-1 text-xs text-fp-text-muted">
                            {{ collect($dudosos)->map(fn ($c) => $nombresDeCampos[$c] ?? $c)->join(', ') }}
                        </p>
                    @else
                        <span class="text-xs text-fp-text-muted">—</span>
                    @endif
                </td>

                {{-- TG-282 (E5-05): el recorte que propuso la IA. Las tres
                     salidas dejan aprobar; lo que no se hace es publicar una
                     propuesta que nadie miró. --}}
                <td class="px-4 py-3">
                    @if ($producto->foto_propuesta_url)
                        <img src="{{ $producto->foto_propuesta_url }}"
                            alt="Foto propuesta para {{ $datos['codigo_catalogo'] ?? 'el producto' }}"
                            width="96" height="96" loading="lazy" decoding="async"
                            class="h-24 w-24 rounded object-cover {{ $producto->foto_estado === 'confirmada' ? 'ring-2 ring-fp-primary' : 'ring-1 ring-slate-200' }}">
                    @else
                        <span class="text-xs text-fp-text-muted">Sin foto</span>
                    @endif

                    @if ($producto->estado !== 'descartado')
                        <div class="mt-1 space-y-1">
                            @if ($producto->foto_estado === 'confirmada')
                                <p class="text-xs font-semibold text-fp-primary">Confirmada</p>
                            @elseif ($producto->foto_estado === 'descartada')
                                <p class="text-xs text-fp-text-muted">Se aprueba sin foto</p>
                            @elseif ($producto->foto_propuesta_url)
                                <button type="button" wire:click="confirmarFoto({{ $producto->id }})"
                                    class="fp-accion w-full">Usar esta</button>
                            @endif

                            <button type="button" wire:click="pedirFoto({{ $producto->id }})"
                                class="fp-accion w-full">{{ $producto->foto_propuesta_url ? 'Subir otra' : 'Subir foto' }}</button>

                            @if ($producto->foto_propuesta_url && $producto->foto_estado !== 'descartada')
                                <button type="button" wire:click="descartarFoto({{ $producto->id }})"
                                    class="fp-accion w-full">Dejar sin foto</button>
                            @endif
                        </div>
                    @endif
                </td>

                <td class="px-4 py-3 text-right whitespace-nowrap">
                    @if ($producto->estado === 'descartado')
                        <button type="button" wire:click="recuperar({{ $producto->id }})"
                            class="fp-accion fp-accion-primaria">Recuperar</button>
                    @else
                        <button type="button" wire:click="editar({{ $producto->id }})"
                            class="fp-accion fp-accion-primaria">Corregir</button>
                        <button type="button" wire:click="descartar({{ $producto->id }})"
                            wire:confirm="¿Descartar este producto? No entrará al catálogo."
                            class="fp-accion fp-accion-peligro">Descartar</button>
                    @endif
                </td>
            </tr>

            @if ($subiendoFotoDe === $producto->id)
                <tr>
                    <td colspan="9" class="bg-fp-page px-4 py-4">
                        <form wire:submit="subirFoto" class="flex flex-wrap items-end gap-3">
                            <div>
                                <label for="revisar-foto-{{ $producto->id }}" class="mb-1 block text-sm font-medium">
                                    Foto para {{ $datos['codigo_catalogo'] ?? 'este producto' }}
                                </label>
                                <input type="file" id="revisar-foto-{{ $producto->id }}" wire:model="fotoNueva"
                                    accept="image/jpeg,image/png" class="rounded-lg text-sm">
                                <p class="mt-1 text-xs text-fp-text-muted">jpg o png, hasta 5 MB.</p>
                                @error('fotoNueva') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                            </div>
                            <button class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="fotoNueva,subirFoto">Guardar foto</button>
                            <button type="button" wire:click="cancelarFoto" class="fp-btn fp-btn-secundario">Cancelar</button>
                            <span wire:loading wire:target="fotoNueva" class="text-xs text-fp-text-muted">Subiendo…</span>
                        </form>
                    </td>
                </tr>
            @endif

            @if ($editandoId === $producto->id)
                <tr>
                    <td colspan="9" class="bg-fp-page px-4 py-4">
                        <form wire:submit="guardar" class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($nombresDeCampos as $campo => $titulo)
                                <div>
                                    <label class="mb-1 block text-sm font-medium">
                                        {{ $titulo }}
                                        @if (in_array($campo, $dudosos, true))
                                            <span class="ml-1 rounded bg-fp-badge-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-fp-badge-warning-fg">dudoso</span>
                                        @endif
                                    </label>

                                    @if ($campo === 'categoria_id')
                                        <select wire:model="campos.{{ $campo }}" class="w-full rounded-lg text-sm">
                                            <option value="">La del lote</option>
                                            @foreach ($this->categorias as $categoria)
                                                <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($campo === 'medios_numeros')
                                        <select wire:model="campos.{{ $campo }}" class="w-full rounded-lg text-sm">
                                            <option value="">No se sabe</option>
                                            <option value="1">Sí, hay medias tallas</option>
                                            <option value="0">No, solo enteros</option>
                                        </select>
                                    @else
                                        <input type="text" wire:model="campos.{{ $campo }}"
                                            class="w-full rounded-lg text-sm">
                                    @endif

                                    @error('campos.'.$campo) <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
                                </div>
                            @endforeach

                            <div class="sm:col-span-3 lg:col-span-4 flex gap-2">
                                <x-panel.boton type="submit" tamano="sm" wire:loading.attr="disabled">Guardar</x-panel.boton>
                                <x-panel.boton type="button" variante="secundario" tamano="sm" wire:click="cancelar">Cancelar</x-panel.boton>
                            </div>
                        </form>
                    </td>
                </tr>
            @endif
        @empty
            <x-panel.vacio colspan="9"
                :mensaje="$filtro === 'por_revisar' ? 'No queda nada por revisar en este catálogo.' : 'No hay productos que mostrar.'" />
        @endforelse
    </x-panel.tabla>
</div>
