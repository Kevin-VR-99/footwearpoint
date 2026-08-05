<?php

use App\Models\ConfiguracionCiclo;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Distribuidoras — Admin')] class extends Component
{
    public string $filtroEstado = '';
    public string $mensaje = '';

    // Asignar suscripción
    public bool $mostrarSuscripcion = false;
    public ?int $distribuidoraSuscripcionId = null;
    public string $distribuidoraSuscripcionNombre = '';
    public string $plan_id = '';
    public string $lineas_extra_contratadas = '0';
    public string $meses = '1';
    public bool $renovacion_automatica = true;

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(0);

        if (!Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    public function getDistribuidorasProperty()
    {
        $query = Distribuidora::query()->orderByDesc('id');

        if ($this->filtroEstado !== '') {
            $query->where('estado', $this->filtroEstado);
        }

        return $query->get();
    }

    public function getPlanesActivosProperty()
    {
        return PlanSuscripcion::where('activo', true)
            ->orderBy('precio_base_mensual')
            ->get();
    }

    public function aprobar(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'pendiente') {
            $this->mensaje = 'Solo se pueden aprobar distribuidoras pendientes.';
            return;
        }

        $plan = PlanSuscripcion::where('nombre', 'Básico')->first()
            ?? PlanSuscripcion::first();

        if (!$plan) {
            $this->mensaje = 'No hay planes configurados.';
            return;
        }

        DB::transaction(function () use ($distribuidora, $plan) {
            $distribuidora->update([
                'estado'           => 'activa',
                'fecha_aprobacion' => now(),
            ]);

            Sucursal::withoutGlobalScopes()->firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'es_principal'     => true,
                ],
                [
                    'nombre'    => 'Sucursal Principal',
                    'direccion' => $distribuidora->direccion_publica ?? 'Sin dirección',
                    'telefono'  => $distribuidora->telefono_publico,
                    'activa'    => true,
                ]
            );

            ConfiguracionDistribuidora::withoutGlobalScopes()->firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'anticipo_por_producto'    => 100.00,
                    'dias_solicitud_cambio'    => 12,
                    'dias_gestion_devolucion'  => 20,
                    'dias_vigencia_vale'       => 90,
                    'dias_maximos_recoleccion' => 5,
                    'moneda'                   => 'MXN',
                    'zona_horaria'             => 'America/Mexico_City',
                ]
            );

            ConfiguracionCiclo::withoutGlobalScopes()->firstOrCreate(
                ['distribuidora_id' => $distribuidora->id],
                [
                    'dia_cierre'             => 5,
                    'hora_cierre'            => '18:00:00',
                    'dia_solicitud_fabrica'  => 5,
                    'dias_estimados_llegada' => 5,
                    'activa'                 => true,
                ]
            );

            Suscripcion::withoutGlobalScopes()->create([
                'distribuidora_id'              => $distribuidora->id,
                'plan_id'                       => $plan->id,
                'fecha_inicio'                  => now()->toDateString(),
                'fecha_fin'                     => now()->addMonth()->toDateString(),
                'estado'                        => 'activa',
                'precio_base_contratado'        => $plan->precio_base_mensual,
                'lineas_incluidas_contratadas'  => $plan->lineas_incluidas,
                'precio_linea_extra_contratado' => $plan->precio_linea_extra,
                'lineas_extra_contratadas'      => 0,
                'renovacion_automatica'         => true,
            ]);
        });

        $this->mensaje = "Distribuidora «{$distribuidora->nombre_comercial}» aprobada.";
    }

    public function suspender(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'activa') {
            $this->mensaje = 'Solo se pueden suspender distribuidoras activas.';
            return;
        }

        $d->update(['estado' => 'suspendida']);
        $this->mensaje = "Distribuidora «{$d->nombre_comercial}» suspendida.";
    }

    public function reactivar(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'suspendida') {
            $this->mensaje = 'Solo se pueden reactivar distribuidoras suspendidas.';
            return;
        }

        $d->update(['estado' => 'activa']);
        $this->mensaje = "Distribuidora «{$d->nombre_comercial}» reactivada.";
    }

    public function toggleMarketplace(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'activa' && !$d->marketplace_visible) {
            $this->mensaje = 'Solo distribuidoras activas pueden ser visibles en marketplace.';
            return;
        }

        $d->update(['marketplace_visible' => !$d->marketplace_visible]);
        $estado = $d->marketplace_visible ? 'visible' : 'oculta';
        $this->mensaje = "Marketplace: «{$d->nombre_comercial}» ahora está {$estado}.";
    }

    public function abrirSuscripcion(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if (!in_array($d->estado, ['activa', 'suspendida'])) {
            $this->mensaje = 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.';
            return;
        }

        $this->distribuidoraSuscripcionId = $d->id;
        $this->distribuidoraSuscripcionNombre = $d->nombre_comercial;
        $this->plan_id = '';
        $this->lineas_extra_contratadas = '0';
        $this->meses = '1';
        $this->renovacion_automatica = true;
        $this->mostrarSuscripcion = true;
    }

    public function cancelarSuscripcion()
    {
        $this->mostrarSuscripcion = false;
        $this->distribuidoraSuscripcionId = null;
    }

    public function guardarSuscripcion()
    {
        $this->validate([
            'plan_id'                  => 'required|exists:planes_suscripcion,id',
            'lineas_extra_contratadas' => 'nullable|integer|min:0',
            'meses'                    => 'required|integer|min:1|max:24',
        ]);

        $distribuidora = Distribuidora::findOrFail($this->distribuidoraSuscripcionId);
        $plan = PlanSuscripcion::findOrFail($this->plan_id);

        if (!$plan->activo) {
            $this->mensaje = 'El plan seleccionado no está activo.';
            return;
        }

        Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'activa')
            ->update([
                'estado'    => 'cancelada',
                'fecha_fin' => now()->toDateString(),
            ]);

        Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id'              => $distribuidora->id,
            'plan_id'                       => $plan->id,
            'fecha_inicio'                  => now()->toDateString(),
            'fecha_fin'                     => now()->addMonths((int) $this->meses)->toDateString(),
            'estado'                        => 'activa',
            'precio_base_contratado'        => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas'  => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas'      => (int) $this->lineas_extra_contratadas,
            'renovacion_automatica'         => $this->renovacion_automatica,
        ]);

        $this->mensaje = "Suscripción «{$plan->nombre}» asignada a «{$distribuidora->nombre_comercial}».";
        $this->cancelarSuscripcion();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Distribuidoras</h2>
            <p class="text-sm text-slate-500">Solicitudes y estado de las tiendas</p>
        </div>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    @if ($mostrarSuscripcion)
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="font-semibold mb-1">Asignar plan</h3>
            <p class="text-sm text-slate-500 mb-4">{{ $distribuidoraSuscripcionNombre }}</p>

            <form wire:submit="guardarSuscripcion" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Plan</label>
                    <select wire:model="plan_id" class="w-full rounded-lg border-slate-300">
                        <option value="">Selecciona un plan</option>
                        @foreach ($this->planesActivos as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->nombre }} — ${{ number_format($plan->precio_base_mensual, 2) }}
                                ({{ $plan->lineas_incluidas }} líneas)
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Líneas extra</label>
                    <input type="number" min="0" wire:model="lineas_extra_contratadas"
                           class="w-full rounded-lg border-slate-300">
                    @error('lineas_extra_contratadas') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Meses</label>
                    <input type="number" min="1" max="24" wire:model="meses"
                           class="w-full rounded-lg border-slate-300">
                    @error('meses') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" wire:model="renovacion_automatica"
                           class="rounded border-slate-300 text-blue-600">
                    <span class="text-sm">Renovación automática</span>
                </div>

                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="rounded-lg bg-[#111E38] text-white text-sm px-4 py-2">
                        Asignar
                    </button>
                    <button type="button" wire:click="cancelarSuscripcion"
                            class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="mb-4 flex gap-2 flex-wrap">
        <button wire:click="$set('filtroEstado', '')"
                class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === '' ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
            Todas
        </button>
        @foreach (['pendiente', 'activa', 'suspendida', 'rechazada'] as $estado)
            <button wire:click="$set('filtroEstado', '{{ $estado }}')"
                    class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === $estado ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
                {{ ucfirst($estado) }}
            </button>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">ID</th>
                    <th class="text-left px-4 py-3 font-medium">Nombre</th>
                    <th class="text-left px-4 py-3 font-medium">Estado</th>
                    <th class="text-left px-4 py-3 font-medium">Marketplace</th>
                    <th class="text-left px-4 py-3 font-medium">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->distribuidoras as $d)
                    <tr>
                        <td class="px-4 py-3">{{ $d->id }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $d->nombre_comercial }}</div>
                            <div class="text-xs text-slate-400">{{ $d->slug }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $d->estado === 'activa' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $d->estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $d->estado === 'suspendida' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $d->estado === 'rechazada' ? 'bg-slate-100 text-slate-600' : '' }}
                            ">
                                {{ $d->estado }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="toggleMarketplace({{ $d->id }})"
                                    class="text-xs {{ $d->marketplace_visible ? 'text-green-600' : 'text-slate-400' }}">
                                {{ $d->marketplace_visible ? 'Visible' : 'Oculta' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            @if ($d->estado === 'pendiente')
                                <button wire:click="aprobar({{ $d->id }})"
                                        class="text-xs text-green-700 hover:underline">Aprobar</button>
                            @endif
                            @if ($d->estado === 'activa')
                                <button wire:click="suspender({{ $d->id }})"
                                        class="text-xs text-red-600 hover:underline">Suspender</button>
                            @endif
                            @if ($d->estado === 'suspendida')
                                <button wire:click="reactivar({{ $d->id }})"
                                        class="text-xs text-blue-700 hover:underline">Reactivar</button>
                            @endif
                            @if (in_array($d->estado, ['activa', 'suspendida']))
                                <button wire:click="abrirSuscripcion({{ $d->id }})"
                                        class="text-xs text-indigo-700 hover:underline">Asignar plan</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                            No hay distribuidoras con este filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>