<?php

use App\Models\Pedido;
use App\Services\Pedido\EnviarPedidoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Detalle pedido — FootwearPoint')] class extends Component {
    public int $pedidoId;
    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(int $id)
    {
        if (!Auth::check()) {
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
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle'])
            ->findOrFail($this->pedidoId);
    }

    public function enviar(EnviarPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar($this->pedido);
            $this->mensaje = 'Pedido enviado correctamente.';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo enviar.';
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

        @if ($this->pedido->estado === 'borrador')
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Este pedido sigue en borrador.
                Para agregar o quitar productos usa la captura continua:
                <a href="{{ route('pedidos.create') }}?continuar={{ $this->pedido->id }}"
                    class="font-medium text-[#2563EB] hover:underline">
                    Continuar editando
                </a>
                (o implementamos el alta de líneas aquí mismo).
            </div>
        @endif
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

    <div class="grid gap-4 sm:grid-cols-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Subtotal</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format((float) $this->pedido->subtotal, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Total</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format((float) $this->pedido->total, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Colocación</p>
            <p class="text-sm font-medium">
                {{ optional($this->pedido->fecha_colocacion)->format('d/m/Y H:i') ?? '—' }}
            </p>
        </div>
    </div>

    @if ($this->pedido->observaciones)
        <p class="mb-4 text-sm text-slate-600">
            <span class="font-medium text-slate-800">Observaciones:</span>
            {{ $this->pedido->observaciones }}
        </p>
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
                        <td class="px-4 py-3 text-right tabular-nums">
                            ${{ number_format((float) $l->precio_unitario, 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format((float) $l->subtotal, 2) }}
                        </td>
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
