<?php

use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.panel')] #[Title('Pedidos — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $filtro_estado = '';
    public string $filtro_tipo = '';
    public string $buscar = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    // Al cambiar un filtro se regresa a la primera página.
    public function updatedFiltroEstado(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroTipo(): void
    {
        $this->resetPage();
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    /** Misma consulta para la pantalla y para la exportación (App\Services\Exportar\ExportarPedidos). */
    private function consulta()
    {
        return app(\App\Services\Exportar\ExportarPedidos::class)->consulta($this->filtros());
    }

    private function filtros(): array
    {
        return ['estado' => $this->filtro_estado, 'tipo' => $this->filtro_tipo, 'buscar' => $this->buscar];
    }

    public function getPedidosProperty()
    {
        // Auditoría 21-oct-2026: paginado; antes se cortaba en 100 y los viejos no salían.
        return $this->consulta()->paginate(25);
    }

    /** Aviso cuando la exportación se fue a la cola. */
    public string $avisoExportacion = '';

    /**
     * Exporta a .xlsx los pedidos con los filtros de la pantalla. Hasta
     * config('exportaciones.umbral_listado') filas se descarga al momento;
     * más que eso se arma en la cola y llega el enlace en Notificaciones.
     */
    public function exportar()
    {
        \App\Services\Exportar\ExportarListadoXlsx::exigirPersonal();
        $this->avisoExportacion = '';

        $cola = app(\App\Services\Exportar\ExportacionesEnCola::class);
        if ($cola->pedidosVanALaCola($this->filtros())) {
            $cola->solicitar(Auth::user(), \App\Services\Exportar\ExportarPedidos::TIPO, 'xlsx', $this->filtros());
            $this->avisoExportacion = \App\Services\Exportar\ExportacionesEnCola::MENSAJE_EN_COLA;

            return null;
        }

        return app(\App\Services\Exportar\ExportarPedidos::class)->descargar($this->filtros());
    }
};
?>

<div>
    <x-panel.encabezado titulo="Pedidos" subtitulo="Listado de pedidos de la distribuidora" class="mb-6">
        <x-slot:acciones>
            <button type="button" wire:click="exportar" data-exportar="pedidos"
                class="fp-btn fp-btn-secundario shrink-0" wire:loading.attr="disabled" wire:target="exportar">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                <span wire:loading.remove wire:target="exportar">Exportar Excel</span>
                <span wire:loading wire:target="exportar">Generando…</span>
            </button>
            <x-panel.boton :href="route('pedidos.create')" class="shrink-0">
                Nuevo pedido
            </x-panel.boton>
        </x-slot:acciones>
    </x-panel.encabezado>

    @if ($avisoExportacion !== '')
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status" data-aviso-exportacion>
            {{ $avisoExportacion }} <a href="{{ route('notificaciones.index') }}" class="fp-enlace font-medium" wire:navigate>Ver notificaciones</a>
        </div>
    @endif

    <x-panel.filtros class="mb-4">
        <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Folio o cliente…" etiqueta="Buscar pedidos por folio o cliente" class="w-full sm:w-72" data-buscador-pedidos />

        <select wire:model.live="filtro_estado" aria-label="Filtrar por estado"
                class="rounded-lg text-sm focus:ring-fp-primary">
            <option value="">Todos los estados</option>
            <option value="borrador">Borrador</option>
            <option value="descartado">Descartado</option>
            <option value="colocado">Colocado</option>
            <option value="en_revision">En revisión</option>
            <option value="confirmado">Confirmado</option>
            <option value="incluido_en_ciclo">En ciclo</option>
            <option value="solicitado_fabrica">Solicitado fábrica</option>
            <option value="en_transito">En tránsito</option>
            <option value="recibido_distribuidora">Recibido</option>
            <option value="parcialmente_disponible">Parc. disponible</option>
            <option value="listo_entrega">Listo entrega</option>
            <option value="vencido_recoleccion">Recolección vencida</option>
            <option value="no_surtido">No surtido</option>
            <option value="entregado">Entregado</option>
            <option value="rechazado">Rechazado</option>
            <option value="cancelado">Cancelado</option>
        </select>

        <select wire:model.live="filtro_tipo" aria-label="Filtrar por tipo de cliente"
                class="rounded-lg text-sm focus:ring-fp-primary">
            <option value="">Todos los tipos</option>
            <option value="cliente_directo">Cliente directo</option>
            <option value="revendedor">Cliente mayorista</option>
        </select>
    </x-panel.filtros>

    <x-panel.tabla>
        <x-slot:encabezado>
                <tr>
                    <th class="px-4 py-3 font-medium">Folio</th>
                    <th class="px-4 py-3 font-medium">Tipo</th>
                    <th class="px-4 py-3 font-medium">Propietario</th>
                    <th class="px-4 py-3 font-medium">Estado</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
        </x-slot:encabezado>
                @forelse ($this->pedidos as $p)
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $p->folio }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $p->tipo === 'cliente_directo' ? 'Cliente' : 'Cliente mayorista' }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $p->clienteDirecto?->nombre
                                ?? $p->revendedorAfiliacion?->revendedor?->nombre
                                ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.insignia-estado :estado="$p->estado" />
                            {{-- TG-125: plazo de recolección de un pedido listo. --}}
                            @switch($p->alertaRecoleccion())
                                @case('vencida')
                                    <x-ui.insignia-estado variante="danger" texto="Recolección vencida" class="ml-1" />
                                    @break
                                @case('por_vencer')
                                    <x-ui.insignia-estado variante="warning" texto="Por vencer" class="ml-1" />
                                    @break
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            ${{ number_format((float) $p->total, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pedidos.show', $p->id) }}"
                               class="fp-enlace text-sm">
                                Ver
                            </a>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="6" mensaje="No hay pedidos." />
                @endforelse
    </x-panel.tabla>

    <div class="mt-4">
        {{ $this->pedidos->links() }}
    </div>
</div>
