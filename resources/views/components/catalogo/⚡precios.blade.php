<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraLinea;
use App\Services\Catalogo\CatalogoParaConfigurarPrecios;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Distribuidora\FijarDescuentoLineaAction;
use App\Services\Distribuidora\FijarDescuentoMayoristaAction;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Precios y publicación de la distribuidora (TG-249, E4-14).
 *
 * Aquí la distribuidora fija su descuento general de mayoreo, pone o quita un
 * precio de mayoreo propio por producto y oculta o vuelve a mostrar productos.
 * El menudeo es el del catálogo y no se puede cambiar.
 *
 * A diferencia de "Temporadas y productos", aquí SÍ aparecen los productos
 * ocultos: es el único lugar desde donde se pueden volver a mostrar.
 * La lógica vive en las Actions; la pantalla solo las llama.
 */
new #[Layout('layouts.panel')] #[Title('Precios y publicación — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $busqueda = '';
    #[Url]
    public string $filtro = CatalogoParaConfigurarPrecios::FILTRO_TODOS;
    public string $descuento = '0';

    /** @var array<int, string> porcentaje capturado por línea activa */
    public array $descuentosLinea = [];

    /** @var array<int, string> precio propio capturado, por producto de temporada */
    public array $preciosPropios = [];

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $this->descuento = (string) (float) (ConfiguracionDistribuidora::query()->value('descuento_mayorista_pct') ?? 0);
        $this->cargarDescuentosDeLinea();
    }

    private function cargarDescuentosDeLinea(): void
    {
        $this->descuentosLinea = DistribuidoraLinea::query()
            ->vigentes()
            ->orderBy('linea_id')
            ->get()
            ->mapWithKeys(fn (DistribuidoraLinea $fila) => [
                $fila->linea_id => $fila->descuento_mayorista_pct === null
                    ? ''
                    : (string) (float) $fila->descuento_mayorista_pct,
            ])
            ->all();
    }

    public function updatedBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatedFiltro(): void
    {
        $this->resetPage();
    }

    public function guardarDescuento(): void
    {
        $this->ejecutar(function () {
            if (! is_numeric(str_replace(',', '.', trim($this->descuento)))) {
                throw new OperacionInvalidaException('Escribe el descuento como un número, por ejemplo 15.', 422);
            }

            $config = app(FijarDescuentoMayoristaAction::class)->ejecutar((float) str_replace(',', '.', trim($this->descuento)));
            $this->descuento = (string) (float) $config->descuento_mayorista_pct;

            $this->cargarDescuentosDeLinea();

            return 'Descuento general de mayoreo guardado.';
        }, 'No se pudo guardar el descuento.');
    }

    public function guardarDescuentoLinea(int $lineaId): void
    {
        $this->ejecutar(function () use ($lineaId) {
            $texto = str_replace(',', '.', trim((string) ($this->descuentosLinea[$lineaId] ?? '')));

            if ($texto === '') {
                app(FijarDescuentoLineaAction::class)->ejecutar($lineaId, null);
                $this->descuentosLinea[$lineaId] = '';

                return 'Esta línea vuelve a usar el descuento general.';
            }

            if (! is_numeric($texto)) {
                throw new OperacionInvalidaException('Escribe el descuento de la línea como un número, por ejemplo 15.', 422);
            }

            $fila = app(FijarDescuentoLineaAction::class)->ejecutar($lineaId, (float) $texto);
            $this->descuentosLinea[$lineaId] = (string) (float) $fila->descuento_mayorista_pct;

            return 'Descuento de la línea guardado.';
        }, 'No se pudo guardar el descuento de la línea.');
    }

    public function guardarPrecio(int $productoCampanaId): void
    {
        $this->ejecutar(function () use ($productoCampanaId) {
            $valor = str_replace([',', '$', ' '], ['.', '', ''], (string) ($this->preciosPropios[$productoCampanaId] ?? ''));

            if ($valor === '' || ! is_numeric($valor)) {
                throw new OperacionInvalidaException('Escribe el precio de mayoreo como un número.', 422);
            }

            app(GestionarOfertaDistribuidoraAction::class)->ponerPrecioMayorista($productoCampanaId, (float) $valor);
            unset($this->preciosPropios[$productoCampanaId]);

            return 'Precio de mayoreo guardado.';
        }, 'No se pudo guardar el precio.');
    }

    public function quitarPrecio(int $productoCampanaId): void
    {
        $this->ejecutar(function () use ($productoCampanaId) {
            app(GestionarOfertaDistribuidoraAction::class)->quitarPrecioMayorista($productoCampanaId);
            unset($this->preciosPropios[$productoCampanaId]);

            return 'Listo: ese producto vuelve a usar el descuento de su línea, o el general si la línea no tiene el suyo.';
        }, 'No se pudo quitar el precio.');
    }

    public function ocultar(int $productoCampanaId): void
    {
        $this->ejecutar(function () use ($productoCampanaId) {
            app(GestionarOfertaDistribuidoraAction::class)->ocultar($productoCampanaId);

            return 'Producto oculto: tus clientes ya no lo ven.';
        }, 'No se pudo ocultar el producto.');
    }

    public function mostrar(int $productoCampanaId): void
    {
        $this->ejecutar(function () use ($productoCampanaId) {
            app(GestionarOfertaDistribuidoraAction::class)->mostrar($productoCampanaId);

            return 'Producto visible otra vez para tus clientes.';
        }, 'No se pudo mostrar el producto.');
    }

    private function ejecutar(callable $accion, string $generico): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $this->mensaje = $accion();
            app(PrecioEfectivo::class)->olvidarLoConsultado();
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, $generico);
        }
    }

    public function getProductosProperty()
    {
        return app(CatalogoParaConfigurarPrecios::class)->pagina($this->busqueda, $this->filtro);
    }

    public function getPreciosProperty(): PrecioEfectivo
    {
        return app(PrecioEfectivo::class);
    }

    public function getLineasActivasProperty()
    {
        return DistribuidoraLinea::query()->vigentes()->with('linea')->orderBy('linea_id')->get();
    }
}; ?>

<div class="space-y-4">
    <x-panel.encabezado titulo="Precios y publicación"
        subtitulo="El menudeo es el del catálogo y es igual para todos. Tu mayoreo (lo que paga tu cliente mayorista) y qué productos ocultas lo decides tú." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    <div class="fp-tarjeta p-4 fp-formulario fp-aparecer">
        <form wire:submit="guardarDescuento" class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block font-medium text-slate-700">Descuento general de mayoreo (%)</span>
                <input type="text" inputmode="decimal" wire:model="descuento" class="mt-1 w-32 rounded-lg text-sm">
            </label>
            <button type="submit" class="fp-btn fp-btn-primario">Guardar descuento</button>
            <p class="text-xs text-fp-text-muted">Se usa en las líneas activas que todavía no tienen un descuento propio, y en los productos sin precio propio.</p>
        </form>
    </div>

    <div class="fp-tarjeta p-4 fp-aparecer">
        <h2 class="text-sm font-semibold text-slate-800">Descuento por línea activa</h2>
        <p class="mt-1 text-xs text-fp-text-muted">Cada línea puede tener un porcentaje distinto. Si dejas el campo vacío y guardas, esa línea usa el descuento general.</p>
        @if ($this->lineasActivas->isEmpty())
            <p class="mt-3 text-sm text-slate-500">Todavía no tienes líneas activas.</p>
        @else
            <div class="mt-3 space-y-2">
                @foreach ($this->lineasActivas as $fila)
                    <form wire:submit="guardarDescuentoLinea({{ $fila->linea_id }})" wire:key="descuento-linea-{{ $fila->linea_id }}" class="flex flex-wrap items-end gap-3">
                        <label class="text-sm">
                            <span class="block font-medium text-slate-700">{{ $fila->linea?->nombre }} (%)</span>
                            <input type="text" inputmode="decimal" wire:model="descuentosLinea.{{ $fila->linea_id }}"
                                placeholder="General {{ $descuento }}%" class="mt-1 w-32 rounded-lg text-sm">
                        </label>
                        <button type="submit" class="fp-btn fp-btn-primario">Guardar</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>

    <div class="relative flex flex-wrap gap-3 fp-tarjeta p-4 fp-formulario fp-aparecer">
        <x-ui.cargando />
        <x-ui.buscador wire:model.live.debounce.400ms="busqueda"
            placeholder="Buscar por nombre, modelo, código o marca" etiqueta="Buscar productos" class="min-w-64 flex-1" />
        <select wire:model.live="filtro" aria-label="Filtrar productos" class="rounded-lg text-sm">
            <option value="todos">Todos</option>
            <option value="ocultos">Solo ocultos</option>
            <option value="precio_propio">Solo con precio propio</option>
        </select>
    </div>

    <div class="relative overflow-x-auto fp-tarjeta fp-aparecer">
        <x-ui.cargando />
        <table class="fp-tabla min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-4 py-3">Producto</th>
                    <th class="px-4 py-3">Línea / temporada</th>
                    <th class="px-4 py-3 text-right">Menudeo</th>
                    <th class="px-4 py-3 text-right">Tu mayoreo</th>
                    <th class="px-4 py-3">Precio propio</th>
                    <th class="px-4 py-3">Publicación</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->productos as $pc)
                    @php($oculto = $pc->oferta && ! $pc->oferta->publicado)
                    @php($propio = $pc->oferta?->precio_mayorista)
                    <tr wire:key="pc-{{ $pc->id }}" @class(['bg-slate-50 text-slate-500' => $oculto])>
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $pc->producto?->nombre }}</p>
                            <p class="text-xs text-fp-text-muted">{{ $pc->producto?->marca?->nombre }} · {{ $pc->codigo_catalogo }}</p>
                        </td>
                        <td class="px-4 py-3">
                            {{ $pc->campana?->linea?->nombre }}
                            <span class="block text-xs text-fp-text-muted">{{ $pc->campana?->nombre }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format($this->precios->menudeo($pc), 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-fp-primary">
                            ${{ number_format($this->precios->mayoreo($pc), 2) }}
                            <span class="block text-xs font-normal text-fp-text-muted">{{ $propio !== null ? 'Precio propio' : 'Con tu descuento' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <input type="text" inputmode="decimal" wire:model="preciosPropios.{{ $pc->id }}" aria-label="Precio propio de {{ $pc->producto?->nombre ?? 'producto' }}"
                                    placeholder="{{ $propio !== null ? number_format((float) $propio, 2, '.', '') : 'Ej. 650' }}"
                                    class="w-24 rounded-lg text-sm">
                                <button type="button" wire:click="guardarPrecio({{ $pc->id }})" class="fp-enlace text-sm font-medium">Guardar</button>
                                @if ($propio !== null)
                                    <button type="button" wire:click="quitarPrecio({{ $pc->id }})" wire:confirm="¿Quitar tu precio propio? Se usará el precio del catálogo." class="fp-accion fp-accion-neutra">Quitar</button>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($oculto)
                                <span class="fp-insignia fp-insignia-neutral mr-2">Oculto</span>
                                <button type="button" wire:click="mostrar({{ $pc->id }})" class="fp-enlace text-sm font-medium">Mostrar</button>
                            @else
                                <span class="fp-insignia fp-insignia-success mr-2">Visible</span>
                                <button type="button" wire:click="ocultar({{ $pc->id }})" class="text-sm text-red-600">Ocultar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-fp-text-muted">
                            No hay productos que coincidan. Revisa que tengas líneas activas con una temporada activa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->productos->links() }}
</div>
