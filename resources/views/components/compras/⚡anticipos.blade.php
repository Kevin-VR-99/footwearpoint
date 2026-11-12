<?php

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Services\Auth\PermisoModuloService;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Reporte\PagosDePedidos;
use App\Services\Reporte\PedidosPorRevendedorAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Anticipos — FootwearPoint')] class extends Component
{
    public string $anticipo_por_producto = '0';

    public int $dias_maximos_recoleccion = 5;

    public int $dias_solicitud_cambio = 12;

    public int $dias_gestion_devolucion = 20;

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        if (! $this->puedeConfigurar) {
            return;
        }

        $config = ConfiguracionDistribuidora::query()->first();
        if ($config === null) {
            return;
        }

        $this->anticipo_por_producto = (string) $config->anticipo_por_producto;
        $this->dias_maximos_recoleccion = (int) $config->dias_maximos_recoleccion;
        $this->dias_solicitud_cambio = (int) $config->dias_solicitud_cambio;
        $this->dias_gestion_devolucion = (int) $config->dias_gestion_devolucion;
    }

    public function getPuedeConfigurarProperty(): bool
    {
        return app(PermisoModuloService::class)
            ->puede(Auth::user(), PermisoModuloService::CONFIGURACION);
    }

    /**
     * Misma cuenta que RegistrarPagoPedidoAction::resumen y el reporte de
     * pendientes: pagado cubre primero el anticipo. No entran borradores ni
     * pedidos cerrados sin venta. Solo cliente directo.
     *
     * @return list<array{id:int, folio:string, cliente:string, estado:string, anticipo:float}>
     */
    public function getPendientesProperty(): array
    {
        $pedidos = Pedido::query()
            ->with('clienteDirecto:id,nombre')
            ->where('tipo', 'cliente_directo')
            ->whereNotIn('estado', array_merge(
                PedidosPorRevendedorAction::ESTADOS_EXCLUIDOS_EXTRA,
                RegistrarPagoPedidoAction::ESTADOS_CERRADOS,
            ))
            ->orderByDesc('id')
            ->get(['id', 'folio', 'estado', 'cliente_directo_id']);

        $ids = $pedidos->pluck('id')->all();
        $pagos = app(PagosDePedidos::class);
        $pagado = $pagos->pagadoPorPedido($ids);
        $requerido = $pagos->anticipoRequeridoPorPedido($ids);
        $filas = [];

        foreach ($pedidos as $pedido) {
            $req = (float) ($requerido[$pedido->id] ?? 0);
            $pag = (float) ($pagado[$pedido->id] ?? 0);
            $pendiente = round(max(0, $req - min($req, $pag)), 2);
            if ($pendiente <= 0) {
                continue;
            }

            $filas[] = [
                'id' => (int) $pedido->id,
                'folio' => (string) $pedido->folio,
                'cliente' => (string) ($pedido->clienteDirecto?->nombre ?? 'Cliente'),
                'estado' => (string) $pedido->estado,
                'anticipo' => $pendiente,
            ];
        }

        return $filas;
    }

    public function guardar(ActualizarConfiguracionDistribuidoraAction $accion): void
    {
        abort_unless($this->puedeConfigurar, 403);

        $datos = $this->validate([
            'anticipo_por_producto' => ['required', 'numeric', 'min:0'],
            'dias_maximos_recoleccion' => ['required', 'integer', 'min:1'],
            'dias_solicitud_cambio' => ['required', 'integer', 'min:1'],
            'dias_gestion_devolucion' => ['required', 'integer', 'min:1'],
        ]);

        $accion->ejecutar([
            'anticipo_por_producto' => $datos['anticipo_por_producto'],
            'dias_maximos_recoleccion' => $datos['dias_maximos_recoleccion'],
            'dias_solicitud_cambio' => $datos['dias_solicitud_cambio'],
            'dias_gestion_devolucion' => $datos['dias_gestion_devolucion'],
        ]);
    }
};
?>

<div>
    <x-panel.pestanas-compras activa="anticipos" />

    <x-panel.encabezado titulo="Anticipos"
        subtitulo="Anticipo por producto, plazos y pedidos de cliente directo que todavía lo deben." class="mb-6" />

    @if ($this->puedeConfigurar)
        <form wire:submit="guardar" class="fp-tarjeta mb-6 space-y-4 p-5 sm:p-6" data-anticipos-config>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="fp-campo">
                    <label for="anticipos-anticipo-por-producto" class="mb-1 block text-sm font-medium text-slate-700">Anticipo por producto (MXN)</label>
                    <input id="anticipos-anticipo-por-producto" type="number" step="0.01" min="0" wire:model="anticipo_por_producto" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                    @error('anticipo_por_producto') <p class="mt-1 text-sm text-fp-danger">{{ $message }}</p> @enderror
                </div>
                <div class="fp-campo">
                    <label for="anticipos-dias-recoleccion" class="mb-1 block text-sm font-medium text-slate-700">Días máximos de recolección</label>
                    <input id="anticipos-dias-recoleccion" type="number" min="1" wire:model="dias_maximos_recoleccion" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                </div>
                <div class="fp-campo">
                    <label for="anticipos-dias-cambio" class="mb-1 block text-sm font-medium text-slate-700">Días para solicitar cambio</label>
                    <input id="anticipos-dias-cambio" type="number" min="1" wire:model="dias_solicitud_cambio" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                </div>
                <div class="fp-campo">
                    <label for="anticipos-dias-devolucion" class="mb-1 block text-sm font-medium text-slate-700">Días de gestión de devolución</label>
                    <input id="anticipos-dias-devolucion" type="number" min="1" wire:model="dias_gestion_devolucion" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                </div>
            </div>
            <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled">Guardar cambios</button>
        </form>
    @endif

    <div class="fp-tarjeta overflow-hidden" data-anticipos-pendientes>
        <table class="fp-tabla min-w-full text-sm">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left">Pedido</th>
                    <th class="px-4 py-3 text-left">Cliente</th>
                    <th class="px-4 py-3 text-left">Estado</th>
                    <th class="px-4 py-3 text-right">Anticipo pendiente</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->pendientes as $fila)
                    <tr data-anticipo-pedido="{{ $fila['id'] }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('pedidos.show', $fila['id']) }}" wire:navigate class="fp-enlace">{{ $fila['folio'] }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $fila['cliente'] }}</td>
                        <td class="px-4 py-3">{{ $fila['estado'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['anticipo'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">No hay pedidos de cliente directo con anticipo pendiente.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
