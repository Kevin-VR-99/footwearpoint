<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\CicloCompra;
use App\Services\Ciclo\AsignarCicloVigenteService;
use App\Services\Ciclo\TransicionCicloService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Ciclo de compra — FootwearPoint')] class extends Component
{
    public ?int $cicloId = null;

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        // Por defecto se abre el ciclo vigente. Si la distribuidora todavía no
        // tiene ninguno, el servicio lo crea: es el mismo que usa el Paquete D
        // al confirmar un pedido.
        try {
            $this->cicloId = app(AsignarCicloVigenteService::class)
                ->paraDistribuidoraActual()
                ->id;
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    /** Para poder revisar también ciclos ya cerrados o finalizados. */
    public function getCiclosProperty()
    {
        return CicloCompra::query()
            ->orderByDesc('fecha_cierre')
            ->get(['id', 'nombre', 'estado']);
    }

    public function getDetalleProperty()
    {
        if ($this->cicloId === null) {
            return null;
        }

        try {
            return app(TransicionCicloService::class)->ver((int) $this->cicloId);
        } catch (OperacionInvalidaException $e) {
            return null;
        }
    }

    /** La transición permitida depende del estado actual del ciclo. */
    public function getAccionProperty(): ?array
    {
        $detalle = $this->detalle;

        if ($detalle === null) {
            return null;
        }

        return match ($detalle->ciclo->estado) {
            'abierto' => ['cerrar', 'Cerrar ciclo', 'Deja de aceptar pedidos nuevos. No toca los pedidos todavía.'],
            'cerrado' => ['solicitarFabrica', 'Solicitar a fábrica', 'Pasa los pedidos del ciclo a "solicitado a fábrica".'],
            'solicitado' => ['marcarTransito', 'Marcar en tránsito', 'La fábrica ya despachó. No cambia los pedidos.'],
            'en_transito' => ['marcarRecibido', 'Marcar recibido', 'Pasa los pedidos a "recibido en distribuidora" y guarda la fecha.'],
            'recibido' => ['finalizar', 'Finalizar ciclo', 'Solo se permite si ya no quedan pedidos sin resolver.'],
            default => null,
        };
    }

    public function cerrar()
    {
        $this->ejecutar(fn ($s) => $s->cerrar((int) $this->cicloId), 'Ciclo cerrado.');
    }

    public function solicitarFabrica()
    {
        $this->ejecutar(
            fn ($s) => $s->solicitarFabrica((int) $this->cicloId),
            'Pedido enviado a fábrica. Revisa el consolidado por variante.'
        );
    }

    public function marcarTransito()
    {
        $this->ejecutar(fn ($s) => $s->marcarTransito((int) $this->cicloId), 'Ciclo marcado en tránsito.');
    }

    public function marcarRecibido()
    {
        $this->ejecutar(
            fn ($s) => $s->marcarRecibido((int) $this->cicloId),
            'Mercancía recibida. Los pedidos ya están listos para que se calcule su saldo.'
        );
    }

    public function finalizar()
    {
        $this->ejecutar(fn ($s) => $s->finalizar((int) $this->cicloId), 'Ciclo finalizado.');
    }

    /**
     * Toda la regla de negocio vive en TransicionCicloService, el mismo que
     * usa la API. Aquí solo se llama y se traduce el resultado a pantalla.
     */
    private function ejecutar(callable $accion, string $exito): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if ($this->cicloId === null) {
            $this->errorMsg = 'No hay un ciclo seleccionado.';

            return;
        }

        try {
            $accion(app(TransicionCicloService::class));
            $this->mensaje = $exito;
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMsg = 'No se pudo completar la acción. Intenta de nuevo.';
        }
    }

    public function updatedCicloId(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
    }

    public function fecha($valor, bool $conHora = true): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return Carbon::parse($valor)->format($conHora ? 'd/m/Y H:i' : 'd/m/Y');
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Ciclo de compra</h2>
            <p class="text-sm text-slate-500 mt-1">
                Pedidos agrupados por ciclo, consolidado para fábrica y avance del pedido.
            </p>
        </div>

        <div class="w-full sm:w-72">
            <label class="block text-sm font-medium text-slate-700 mb-1">Ciclo</label>
            <select wire:model.live="cicloId"
                    class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                @foreach ($this->ciclos as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }} — {{ $opcion->estado }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">
            {{ $mensaje }}
        </div>
    @endif

    @if ($errorMsg)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">
            {{ $errorMsg }}
        </div>
    @endif

    @if ($this->detalle === null)
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-8 text-center text-slate-500">
            No hay ningún ciclo de compra para mostrar.
        </div>
    @else
        @php($detalle = $this->detalle)
        @php($ciclo = $detalle->ciclo)

        {{-- Encabezado del ciclo --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-6">
            <div class="flex flex-wrap items-center gap-3 mb-5">
                <h3 class="text-lg font-semibold text-slate-900">{{ $ciclo->nombre }}</h3>
                <x-ui.insignia-estado :estado="$ciclo->estado" />
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
                <div>
                    <p class="text-slate-500 text-xs">Apertura</p>
                    <p class="text-slate-900">{{ $this->fecha($ciclo->fecha_apertura) }}</p>
                </div>
                <div>
                    <p class="text-slate-500 text-xs">Cierre</p>
                    <p class="text-slate-900 font-medium">{{ $this->fecha($ciclo->fecha_cierre) }}</p>
                </div>
                <div>
                    <p class="text-slate-500 text-xs">Solicitud a fábrica</p>
                    <p class="text-slate-900">{{ $this->fecha($ciclo->fecha_solicitud_fabrica) }}</p>
                </div>
                <div>
                    <p class="text-slate-500 text-xs">Llegada estimada</p>
                    <p class="text-slate-900">{{ $this->fecha($ciclo->fecha_estimada_llegada, false) }}</p>
                </div>
                <div>
                    <p class="text-slate-500 text-xs">Recepción</p>
                    <p class="text-slate-900">{{ $this->fecha($ciclo->fecha_recepcion) }}</p>
                </div>
            </div>

            @if ($this->accion)
                <div class="mt-5 pt-5 border-t border-slate-100 flex flex-wrap items-center gap-4">
                    <button type="button" wire:click="{{ $this->accion[0] }}" wire:loading.attr="disabled"
                            class="rounded-lg bg-marca-primario text-white px-4 py-2 text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                        {{ $this->accion[1] }}
                    </button>
                    <p class="text-xs text-slate-500 flex-1 min-w-[16rem]">{{ $this->accion[2] }}</p>
                </div>
            @else
                <div class="mt-5 pt-5 border-t border-slate-100">
                    <p class="text-sm text-slate-500">Este ciclo ya está finalizado: no hay más acciones.</p>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Pedidos del ciclo --}}
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">Pedidos del ciclo</h3>
                    <span class="text-xs text-slate-500">{{ $detalle->pedidos->count() }} en total</span>
                </div>

                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 sticky top-0">
                            <tr>
                                <th class="text-left font-medium px-4 py-3">Folio</th>
                                <th class="text-left font-medium px-4 py-3">Tipo</th>
                                <th class="text-left font-medium px-4 py-3">Estado</th>
                                <th class="text-right font-medium px-4 py-3">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($detalle->pedidos as $pedido)
                                <tr class="hover:bg-slate-50" wire:key="pedido-{{ $pedido->id }}">
                                    <td class="px-4 py-3 font-mono text-xs">{{ $pedido->folio }}</td>
                                    <td class="px-4 py-3 text-slate-700">
                                        {{ $pedido->tipo === 'revendedor' ? 'Revendedor' : 'Cliente directo' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-ui.insignia-estado :estado="$pedido->estado" />
                                    </td>
                                    <td class="px-4 py-3 text-right">${{ number_format((float) $pedido->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                        Este ciclo todavía no tiene pedidos.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Consolidado para fábrica --}}
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-800">Consolidado por variante</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Lo que se le pide a fábrica, sumando todos los pedidos del ciclo.
                    </p>
                </div>

                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 sticky top-0">
                            <tr>
                                <th class="text-left font-medium px-4 py-3">Producto</th>
                                <th class="text-left font-medium px-4 py-3">Talla</th>
                                <th class="text-left font-medium px-4 py-3">Color</th>
                                <th class="text-right font-medium px-4 py-3">Piezas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($detalle->consolidado as $renglon)
                                <tr class="hover:bg-slate-50" wire:key="cons-{{ $renglon['variante_id'] }}">
                                    <td class="px-4 py-3">
                                        <div class="text-slate-900">{{ $renglon['producto_nombre'] }}</div>
                                        <div class="text-xs text-slate-500">{{ $renglon['modelo'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-900">{{ $renglon['talla'] }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $renglon['color'] }}</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ $renglon['cantidad_total'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                        Sin líneas que consolidar todavía.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>