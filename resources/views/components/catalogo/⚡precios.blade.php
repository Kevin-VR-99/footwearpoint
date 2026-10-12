<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\ConfiguracionDistribuidora;
use App\Services\Catalogo\CatalogoParaConfigurarPrecios;
use App\Services\Catalogo\PrecioEfectivo;
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

    /** @var array<int, string> precio propio capturado, por producto de temporada */
    public array $preciosPropios = [];

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $this->descuento = (string) (float) (ConfiguracionDistribuidora::query()->value('descuento_mayorista_pct') ?? 0);
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

            return 'Descuento de mayoreo guardado.';
        }, 'No se pudo guardar el descuento.');
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

            return 'Listo: ese producto vuelve a usar tu descuento general.';
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

    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
        <form wire:submit="guardarDescuento" class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block font-medium text-slate-700">Descuento general de mayoreo (%)</span>
                <input type="text" inputmode="decimal" wire:model="descuento" class="mt-1 w-32 rounded-lg border-slate-300 text-sm">
            </label>
            <button type="submit" class="rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Guardar descuento</button>
            <p class="text-xs text-fp-text-muted">Se aplica al precio de catálogo de los productos que no tienen precio propio.</p>
        </form>
    </div>

    <div class="flex flex-wrap gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
        <input type="search" wire:model.live.debounce.400ms="busqueda"
            placeholder="Buscar por nombre, modelo, código o marca"
            class="min-w-64 flex-1 rounded-lg border-slate-300 text-sm">
        <select wire:model.live="filtro" class="rounded-lg border-slate-300 text-sm">
            <option value="todos">Todos</option>
            <option value="ocultos">Solo ocultos</option>
            <option value="precio_propio">Solo con precio propio</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
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
                                <input type="text" inputmode="decimal" wire:model="preciosPropios.{{ $pc->id }}"
                                    placeholder="{{ $propio !== null ? number_format((float) $propio, 2, '.', '') : 'Ej. 650' }}"
                                    class="w-24 rounded-lg border-slate-300 text-sm">
                                <button type="button" wire:click="guardarPrecio({{ $pc->id }})" class="text-sm font-medium text-fp-primary">Guardar</button>
                                @if ($propio !== null)
                                    <button type="button" wire:click="quitarPrecio({{ $pc->id }})" class="text-sm text-slate-600">Quitar</button>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($oculto)
                                <span class="mr-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs">Oculto</span>
                                <button type="button" wire:click="mostrar({{ $pc->id }})" class="text-sm font-medium text-fp-primary">Mostrar</button>
                            @else
                                <span class="mr-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Visible</span>
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
