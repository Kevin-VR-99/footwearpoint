<?php

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Vale;
use App\Services\Vale\EmitirValeAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Vales — FootwearPoint')] class extends Component
{
    public string $propietario_tipo = 'cliente_directo';
    public string $propietario_id = '';
    public string $monto_original = '';
    public string $motivo = '';
    public string $filtro_tipo = '';
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
    }

    public function getValesProperty()
    {
        $query = Vale::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->orderByDesc('id');

        if ($this->filtro_tipo === 'cliente_directo') {
            $query->whereNotNull('cliente_directo_id');
        } elseif ($this->filtro_tipo === 'revendedor') {
            $query->whereNotNull('revendedor_distribuidora_id');
        }

        return $query->get();
    }

    public function getClientesProperty()
    {
        return ClienteDirecto::query()
            ->where('estado', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function getRevendedoresProperty()
    {
        return RevendedorDistribuidora::query()
            ->with('revendedor')
            ->where('estado', 'activo')
            ->get();
    }

    public function emitir(EmitirValeAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'propietario_tipo' => ['required', 'in:cliente_directo,revendedor'],
            'propietario_id'   => ['required', 'integer', 'min:1'],
            'monto_original'   => ['required', 'numeric', 'min:0.01'],
            'motivo'           => ['nullable', 'string', 'max:300'],
        ], [
            'propietario_id.required' => 'Selecciona un propietario.',
            'monto_original.required' => 'El monto es obligatorio.',
            'monto_original.min'      => 'El monto debe ser mayor a cero.',
        ]);

        try {
            $vale = $accion->ejecutar([
                'propietario_tipo' => $this->propietario_tipo,
                'propietario_id'   => (int) $this->propietario_id,
                'monto_original'   => (float) $this->monto_original,
                'motivo'           => $this->motivo !== '' ? $this->motivo : null,
            ]);

            $this->mensaje = "Vale {$vale->folio} emitido correctamente.";
            $this->reset(['propietario_id', 'monto_original', 'motivo']);
            $this->propietario_tipo = 'cliente_directo';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'Datos inválidos.';
        } catch (\Throwable $e) {
            $this->errorMsg = 'No se pudo emitir el vale. Revisa los datos e intenta de nuevo.';
        }
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Vales</h2>
            <p class="text-sm text-slate-500 mt-1">Emisión manual y consulta por propietario.</p>
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

    {{-- Formulario de emisión --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Emitir vale</h3>

        <form wire:submit="emitir" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Tipo de propietario</label>
                <select wire:model.live="propietario_tipo"
                        class="w-full rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
                    <option value="cliente_directo">Cliente directo</option>
                    <option value="revendedor">Revendedor</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Propietario</label>
                @if ($propietario_tipo === 'cliente_directo')
                    <select wire:model="propietario_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
                        <option value="">— Seleccionar cliente —</option>
                        @foreach ($this->clientes as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                @else
                    <select wire:model="propietario_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
                        <option value="">— Seleccionar revendedor —</option>
                        @foreach ($this->revendedores as $r)
                            <option value="{{ $r->id }}">
                                {{ $r->revendedor?->nombre ?? 'Revendedor #'.$r->id }}
                                @if ($r->codigo_interno) ({{ $r->codigo_interno }}) @endif
                            </option>
                        @endforeach
                    </select>
                @endif
                @error('propietario_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Monto (MXN)</label>
                <input type="number" step="0.01" min="0.01" wire:model="monto_original"
                       class="w-full rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]"
                       placeholder="500.00">
                @error('monto_original') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                <input type="text" wire:model="motivo" maxlength="300"
                       class="w-full rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]"
                       placeholder="Ej. compensación, promoción…">
            </div>

            <div class="md:col-span-2">
                <button type="submit"
                        class="rounded-lg bg-[#2563EB] text-white px-4 py-2 text-sm font-medium hover:bg-blue-700">
                    Emitir vale
                </button>
            </div>
        </form>
    </div>

    {{-- Listado --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Vales emitidos</h3>
            <select wire:model.live="filtro_tipo"
                    class="rounded-lg border-slate-300 text-sm focus:border-[#2563EB] focus:ring-[#2563EB]">
                <option value="">Todos</option>
                <option value="cliente_directo">Solo clientes</option>
                <option value="revendedor">Solo revendedores</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left font-medium px-4 py-3">Folio</th>
                        <th class="text-left font-medium px-4 py-3">Propietario</th>
                        <th class="text-right font-medium px-4 py-3">Monto</th>
                        <th class="text-right font-medium px-4 py-3">Saldo</th>
                        <th class="text-left font-medium px-4 py-3">Vence</th>
                        <th class="text-left font-medium px-4 py-3">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->vales as $vale)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono text-xs">{{ $vale->folio }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">
                                    {{ $vale->clienteDirecto?->nombre
                                        ?? $vale->revendedorAfiliacion?->revendedor?->nombre
                                        ?? '—' }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ $vale->cliente_directo_id ? 'Cliente directo' : 'Revendedor' }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">${{ number_format($vale->monto_original, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium">${{ number_format($vale->saldo_actual, 2) }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ optional($vale->fecha_vencimiento)->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $colores = [
                                        'activo'    => 'bg-green-100 text-green-800',
                                        'agotado'   => 'bg-slate-100 text-slate-700',
                                        'vencido'   => 'bg-amber-100 text-amber-800',
                                        'bloqueado' => 'bg-red-100 text-red-800',
                                    ];
                                @endphp
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $colores[$vale->estado] ?? 'bg-slate-100' }}">
                                    {{ ucfirst($vale->estado) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                                No hay vales emitidos todavía.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>