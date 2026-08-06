<?php

use App\Models\ClienteDirecto;
use App\Models\Pedido;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Services\Pedido\AgregarLineaPedidoAction;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Services\Pedido\EnviarPedidoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Nuevo pedido — FootwearPoint')] class extends Component
{
    public string $tipo = 'cliente_directo';
    public string $propietario_id = '';
    public string $sucursal_id = '';
    public string $observaciones = '';

    public ?int $pedidoId = null;

    public string $producto_campana_id = '';
    public string $variante_id = '';
    public string $cantidad = '1';

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

        $primera = Sucursal::query()->orderBy('id')->value('id');
        $this->sucursal_id = $primera ? (string) $primera : '';
    }

    public function updatedTipo()
    {
        $this->propietario_id = '';
    }

    public function updatedProductoCampanaId()
    {
        $this->variante_id = '';
    }

    public function getClientesProperty()
    {
        return ClienteDirecto::query()->orderBy('nombre')->get();
    }

    public function getRevendedoresProperty()
    {
        return RevendedorDistribuidora::query()
            ->with('revendedor')
            ->orderBy('id')
            ->get();
    }

    public function getSucursalesProperty()
    {
        return Sucursal::query()->orderBy('id')->get();
    }

    public function getCatalogoProperty()
    {
        return ProductoCampana::query()
            ->where('publicado', true)
            ->whereHas('campana', fn ($q) => $q->where('estado', 'activa'))
            ->with([
                'producto',
                'disponibilidadPorVariante' => fn ($q) => $q->where('estado', 'disponible'),
                'disponibilidadPorVariante.variante.talla',
                'disponibilidadPorVariante.variante.color',
            ])
            ->orderBy('id')
            ->get();
    }

    public function getVariantesDisponiblesProperty()
    {
        if ($this->producto_campana_id === '') {
            return collect();
        }

        $pc = $this->catalogo->firstWhere('id', (int) $this->producto_campana_id);

        return $pc?->disponibilidadPorVariante ?? collect();
    }

    public function getPedidoProperty(): ?Pedido
    {
        if (! $this->pedidoId) {
            return null;
        }

        return Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle'])
            ->find($this->pedidoId);
    }

    public function crearBorrador(CrearPedidoBorradorAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'tipo' => 'required|in:cliente_directo,revendedor',
            'propietario_id' => 'required|integer|min:1',
            'sucursal_id' => 'required|integer|min:1',
            'observaciones' => 'nullable|string|max:2000',
        ]);

        try {
            $pedido = $accion->ejecutar([
                'tipo' => $this->tipo,
                'propietario_id' => (int) $this->propietario_id,
                'sucursal_id' => (int) $this->sucursal_id,
                'observaciones' => $this->observaciones ?: null,
            ]);

            $this->pedidoId = $pedido->id;
            $this->mensaje = 'Borrador creado: '.$pedido->folio;
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo crear.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function agregarLinea(AgregarLineaPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if (! $this->pedidoId) {
            $this->errorMsg = 'Primero crea el borrador.';

            return;
        }

        $this->validate([
            'producto_campana_id' => 'required|integer|min:1',
            'variante_id' => 'required|integer|min:1',
            'cantidad' => 'required|integer|min:1',
        ]);

        try {
            $pedido = Pedido::query()->findOrFail($this->pedidoId);
            $accion->ejecutar($pedido, [
                'producto_campana_id' => (int) $this->producto_campana_id,
                'variante_id' => (int) $this->variante_id,
                'cantidad' => (int) $this->cantidad,
            ]);

            $this->mensaje = 'Línea agregada.';
            $this->cantidad = '1';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo agregar.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function enviar(EnviarPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if (! $this->pedidoId) {
            return;
        }

        try {
            $pedido = Pedido::query()->findOrFail($this->pedidoId);
            $pedido = $accion->ejecutar($pedido);
            $this->mensaje = 'Pedido enviado: '.$pedido->folio;

            return $this->redirect(route('pedidos.show', $pedido->id), navigate: true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo enviar.';
        } catch (\Throwable $e) {
            $this->errorMsg = $e->getMessage();
        }
    }
};
?>

<div>
    <div class="mb-6">
        <a href="{{ route('pedidos.index') }}" class="text-sm text-[#2563EB] hover:underline">← Pedidos</a>
        <h2 class="text-2xl font-bold text-slate-900 mt-1">Nuevo pedido</h2>
        <p class="text-sm text-slate-500 mt-1">Captura borrador, agrega líneas del catálogo y envía</p>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">{{ $mensaje }}</div>
    @endif
    @if ($errorMsg)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $errorMsg }}</div>
    @endif

    {{-- Cabecera --}}
    @if (! $pedidoId)
        <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4 max-w-xl">
            <h3 class="font-semibold text-slate-800">1. Datos del pedido</h3>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Tipo</label>
                <select wire:model.live="tipo" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="cliente_directo">Cliente directo</option>
                    <option value="revendedor">Revendedor</option>
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Propietario</label>
                @if ($tipo === 'cliente_directo')
                    <select wire:model="propietario_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">— Selecciona —</option>
                        @foreach ($this->clientes as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                @else
                    <select wire:model="propietario_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">— Selecciona —</option>
                        @foreach ($this->revendedores as $r)
                            <option value="{{ $r->id }}">{{ $r->revendedor?->nombre ?? ('Afiliación #'.$r->id) }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Sucursal</label>
                <select wire:model="sucursal_id" class="w-full rounded-lg border-slate-300 text-sm">
                    @foreach ($this->sucursales as $s)
                        <option value="{{ $s->id }}">{{ $s->nombre ?? ('Sucursal #'.$s->id) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Observaciones</label>
                <textarea wire:model="observaciones" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
            </div>

            <button type="button" wire:click="crearBorrador" wire:loading.attr="disabled"
                    class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2 hover:bg-blue-700 disabled:opacity-60">
                Crear borrador
            </button>
        </div>
    @else
        {{-- Resumen + líneas --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-slate-500">Borrador</p>
                <p class="text-lg font-semibold">{{ $this->pedido?->folio }}</p>
                <p class="text-sm text-slate-600">
                    Total: ${{ number_format((float) ($this->pedido?->total ?? 0), 2) }}
                </p>
            </div>
            <button type="button" wire:click="enviar" wire:loading.attr="disabled"
                    class="rounded-lg bg-emerald-600 text-white text-sm font-medium px-4 py-2 hover:bg-emerald-700 disabled:opacity-60">
                Enviar pedido
            </button>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4 mb-6 max-w-2xl">
            <h3 class="font-semibold text-slate-800">2. Agregar del catálogo</h3>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Producto (campaña)</label>
                <select wire:model.live="producto_campana_id" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="">— Selecciona —</option>
                    @foreach ($this->catalogo as $pc)
                        <option value="{{ $pc->id }}">
                            {{ $pc->producto?->nombre }} — {{ $pc->codigo_catalogo }} (${{ number_format((float) $pc->precio_mayorista, 2) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Variante</label>
                <select wire:model="variante_id" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="">— Selecciona —</option>
                    @foreach ($this->variantesDisponibles as $d)
                        <option value="{{ $d->variante_id }}">
                            Talla {{ $d->variante?->talla?->valor ?? '?' }}
                            / {{ $d->variante?->color?->nombre ?? '?' }}
                            ({{ $d->variante?->sku }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-600 mb-1">Cantidad</label>
                <input type="number" min="1" wire:model="cantidad"
                       class="w-32 rounded-lg border-slate-300 text-sm" />
            </div>

            <button type="button" wire:click="agregarLinea" wire:loading.attr="disabled"
                    class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2 hover:bg-blue-700 disabled:opacity-60">
                Agregar línea
            </button>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b font-medium">Líneas del pedido</div>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Producto</th>
                        <th class="px-4 py-2">Talla</th>
                        <th class="px-4 py-2">Color</th>
                        <th class="px-4 py-2 text-right">Cant.</th>
                        <th class="px-4 py-2 text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->pedido?->detalle ?? [] as $l)
                        <tr>
                            <td class="px-4 py-3">{{ $l->producto_nombre }}</td>
                            <td class="px-4 py-3">{{ $l->talla }}</td>
                            <td class="px-4 py-3">{{ $l->color }}</td>
                            <td class="px-4 py-3 text-right">{{ $l->cantidad }}</td>
                            <td class="px-4 py-3 text-right">${{ number_format((float) $l->subtotal, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Sin líneas aún</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>