<?php

use App\Models\Pedido;
use App\Services\Pedido\EnviarPedidoAction;
use App\Services\Pedido\MarcarListoEntregaAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Pedido\RegistrarRecoleccionAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Detalle pedido — FootwearPoint')] class extends Component {
    public int $pedidoId;
    public string $mensaje = '';
    public string $errorMsg = '';

    public string $pagoTipo = 'anticipo';
    public string $pagoMetodo = 'efectivo';
    public string $pagoMonto = '';
    public string $pagoReferencia = '';

    public function mount(int $id)
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        $this->pedidoId = $id;
    }

    public function getPedidoProperty()
    {
        return Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle', 'pagos'])
            ->findOrFail($this->pedidoId);
    }

    public function getResumenProperty(): array
    {
        return app(RegistrarPagoPedidoAction::class)->resumen($this->pedido);
    }

    public function enviar(EnviarPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar($this->pedido);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Pedido enviado correctamente.';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo enviar.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function registrarPago(RegistrarPagoPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if ($this->resumen['anticipo_pendiente'] <= 0) {
            $this->pagoTipo = 'saldo_pedido';
        }

        $this->validate([
            'pagoTipo' => 'required|in:anticipo,saldo_pedido',
            'pagoMetodo' => 'required|in:efectivo,transferencia,tarjeta,otro',
            'pagoMonto' => 'required|numeric|min:0.01',
            'pagoReferencia' => 'nullable|string|max:190',
        ]);

        try {
            $accion->ejecutar($this->pedido, [
                'tipo' => $this->pagoTipo,
                'metodo' => $this->pagoMetodo,
                'monto' => $this->pagoMonto,
                'referencia' => $this->pagoReferencia !== '' ? $this->pagoReferencia : null,
            ]);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Pago registrado.';
            $this->pagoReferencia = '';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo registrar el pago.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function marcarListo(MarcarListoEntregaAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar($this->pedido);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Pedido marcado como listo para entrega.';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo marcar.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function registrarRecoleccion(RegistrarRecoleccionAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar($this->pedido);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Recolección registrada.';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo registrar.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <a href="{{ route('pedidos.index') }}" class="text-sm text-[#2563EB] hover:underline">← Pedidos</a>
            <h2 class="text-2xl font-bold text-slate-900 mt-1">{{ $this->pedido->folio }}</h2>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.insignia-estado :estado="$this->pedido->estado" />
                <span class="text-sm text-slate-500">
                    {{ $this->pedido->tipo === 'cliente_directo' ? 'Cliente' : 'Revendedor' }}:
                    {{ $this->pedido->clienteDirecto?->nombre ?? ($this->pedido->revendedorAfiliacion?->revendedor?->nombre ?? '—') }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->pedido->estado === 'borrador')
                <button type="button" wire:click="enviar"
                    class="rounded-lg bg-[#1E2F52] px-4 py-2 text-sm font-medium text-white hover:bg-[#2563EB]">
                    Enviar pedido
                </button>
            @endif

            @if ($this->pedido->estado === 'recibido_distribuidora')
                <button type="button" wire:click="marcarListo"
                    class="rounded-lg bg-[#1E2F52] px-4 py-2 text-sm font-medium text-white hover:bg-[#2563EB]">
                    Listo para entrega
                </button>
            @endif

            @if ($this->pedido->estado === 'listo_entrega')
                <button type="button" wire:click="registrarRecoleccion"
                    class="rounded-lg bg-[#1E2F52] px-4 py-2 text-sm font-medium text-white hover:bg-[#2563EB]">
                    Registrar recolección
                </button>
            @endif
        </div>
    </div>

    @if ($this->pedido->estado === 'borrador')
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Este pedido sigue en borrador.
            <a href="{{ route('pedidos.create') }}?continuar={{ $this->pedido->id }}"
                class="font-medium text-[#2563EB] hover:underline">Continuar editando</a>
        </div>
    @endif

    @if (in_array($this->pedido->estado, ['recibido_distribuidora', 'listo_entrega'], true))
        <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
            La mercancía ya está en sucursal.
            @if ($this->resumen['saldo'] > 0)
                Saldo pendiente:
                <span class="font-semibold tabular-nums">${{ number_format($this->resumen['saldo'], 2) }}</span>.
                Cobra el saldo abajo antes de entregar.
            @else
                No hay saldo pendiente.
            @endif
        </div>
    @endif

    @if ($this->pedido->estado === 'listo_entrega' && $this->pedido->fecha_limite_recoleccion)
        @php
            $limite = $this->pedido->fecha_limite_recoleccion;
            $vencido = now()->greaterThan($limite);
            $porVencer = (! $vencido) && now()->diffInHours($limite, false) <= 48;
        @endphp

        @if ($vencido)
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                El plazo de recolección venció el
                {{ $limite->timezone('America/Mexico_City')->format('d/m/Y H:i') }}.
            </div>
        @elseif ($porVencer)
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                El pedido está por vencer. Límite:
                {{ $limite->timezone('America/Mexico_City')->format('d/m/Y H:i') }}.
            </div>
        @else
            <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                Recoger antes del
                {{ $limite->timezone('America/Mexico_City')->format('d/m/Y H:i') }}.
            </div>
        @endif
    @endif

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

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Total</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format((float) $this->pedido->total, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Pagado</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['pagado'], 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Saldo</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['saldo'], 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Anticipo pendiente</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['anticipo_pendiente'], 2) }}</p>
        </div>
    </div>

    @if ($this->pedido->estado !== 'borrador')
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="font-medium text-slate-800 mb-3">Registrar pago</h3>
            <form wire:submit="registrarPago" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end">
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Tipo</span>
                    <select wire:model="pagoTipo" class="w-full rounded-lg border-slate-300 text-sm">
                        @if ($this->resumen['anticipo_pendiente'] > 0)
                            <option value="anticipo">Anticipo</option>
                        @endif
                        <option value="saldo_pedido">Saldo</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Método</span>
                    <select wire:model="pagoMetodo" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="efectivo">Efectivo</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="tarjeta">Tarjeta</option>
                        <option value="otro">Otro</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Monto</span>
                    <input type="number" step="0.01" min="0.01" wire:model="pagoMonto"
                        class="w-full rounded-lg border-slate-300 text-sm" placeholder="0.00">
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Referencia</span>
                    <input type="text" wire:model="pagoReferencia"
                        class="w-full rounded-lg border-slate-300 text-sm" maxlength="190">
                </label>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit"
                        class="rounded-lg bg-[#2563EB] px-4 py-2 text-sm font-medium text-white hover:bg-[#1E2F52]">
                        Registrar pago
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if ($this->pedido->pagos->isNotEmpty())
        <div class="mb-6 bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-800">Pagos</div>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Folio</th>
                        <th class="px-4 py-2 font-medium">Tipo</th>
                        <th class="px-4 py-2 font-medium">Método</th>
                        <th class="px-4 py-2 font-medium text-right">Monto</th>
                        <th class="px-4 py-2 font-medium">Fecha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($this->pedido->pagos as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p->folio }}</td>
                            <td class="px-4 py-2">{{ $p->tipo }}</td>
                            <td class="px-4 py-2">{{ $p->metodo }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">${{ number_format((float) $p->monto, 2) }}</td>
                            <td class="px-4 py-2">{{ optional($p->fecha_pago)->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-800">Líneas</div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Producto</th>
                    <th class="px-4 py-2 font-medium">Talla</th>
                    <th class="px-4 py-2 font-medium">Color</th>
                    <th class="px-4 py-2 font-medium text-right">Cant.</th>
                    <th class="px-4 py-2 font-medium text-right">P. unit.</th>
                    <th class="px-4 py-2 font-medium text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->pedido->detalle as $l)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900">{{ $l->producto_nombre }}</div>
                            <div class="text-xs text-slate-500">{{ $l->modelo }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $l->talla }}</td>
                        <td class="px-4 py-3">{{ $l->color }}</td>
                        <td class="px-4 py-3 text-right">{{ $l->cantidad }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format((float) $l->precio_unitario, 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format((float) $l->subtotal, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">Sin líneas</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>