<?php

use App\Models\CajaSesion;
use App\Models\Pago;
use App\Models\Sucursal;
use App\Services\Auth\PermisoModuloService;
use App\Services\Caja\CajaService;
use App\Support\ContextoOperativo;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Caja y cortes del punto de venta: abrir con fondo, ingresos y retiros,
 * corte (esperado vs contado por método) e historial con PDF.
 */
new #[Layout('layouts.panel')] #[Title('Caja y cortes — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $fondo = '0';
    public string $sucursalId = '';
    public string $notasApertura = '';

    public string $movTipo = 'retiro';
    public string $movMonto = '';
    public string $movMotivo = '';

    public array $contado = ['efectivo' => '', 'transferencia' => '', 'tarjeta' => '', 'otro' => ''];
    public string $notasCierre = '';

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless(app(PermisoModuloService::class)->puede(Auth::user(), PermisoModuloService::CAJA_OPERAR), 403, MensajeError::SIN_PERMISO);

        $this->sucursalId = (string) (Sucursal::query()->where('es_principal', true)->value('id') ?? '');
    }

    public function getEsAdminProperty(): bool
    {
        return (bool) Auth::user()?->hasRole('admin_distribuidora');
    }

    public function getCajaProperty(): ?CajaSesion
    {
        return app(CajaService::class)->abiertaDe();
    }

    public function getResumenProperty(): ?array
    {
        return $this->caja ? app(CajaService::class)->resumen($this->caja) : null;
    }

    public function getSucursalesProperty()
    {
        return Sucursal::query()->where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(['id', 'nombre']);
    }

    public function getHistorialProperty()
    {
        $staffId = app(ContextoOperativo::class)->staff()->id;

        return CajaSesion::query()
            ->with(['abiertaPor.usuario:id,nombre', 'sucursal:id,nombre'])
            ->where('estado', 'cerrada')
            // El empleado ve sus cortes; el admin, los de todos.
            ->when(! $this->esAdmin, fn ($q) => $q->where('abierta_por_staff_id', $staffId))
            ->orderByDesc('cerrada_at')
            ->paginate(10);
    }

    private function intentar(callable $accion, string $porOmision): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->resetErrorBag();

        try {
            $accion();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $errores) {
                $this->addError($campo, $errores[0]);
            }
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, $porOmision);
        }
    }

    public function abrir(CajaService $cajas): void
    {
        $this->intentar(function () use ($cajas) {
            if (! is_numeric($this->fondo)) {
                throw ValidationException::withMessages(['fondo' => ['Escribe el fondo inicial (puede ser 0).']]);
            }
            $cajas->abrir((float) $this->fondo, $this->sucursalId !== '' ? (int) $this->sucursalId : null, $this->notasApertura);
            $this->mensaje = 'Caja abierta. Lo que cobres en mostrador entra a esta caja.';
            $this->notasApertura = '';
        }, 'No se pudo abrir la caja.');
    }

    public function registrarMovimiento(CajaService $cajas): void
    {
        $this->intentar(function () use ($cajas) {
            if (! is_numeric($this->movMonto)) {
                throw ValidationException::withMessages(['movMonto' => ['Escribe el monto.']]);
            }
            try {
                $cajas->registrarMovimiento($this->movTipo, (float) $this->movMonto, $this->movMotivo);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => ['mov'.ucfirst($k) => $v])->all());
            }
            $this->mensaje = ($this->movTipo === 'retiro' ? 'Retiro' : 'Ingreso').' de $'.number_format((float) $this->movMonto, 2).' registrado.';
            $this->movMonto = '';
            $this->movMotivo = '';
        }, 'No se pudo registrar el movimiento.');
    }

    public function cerrar(CajaService $cajas): void
    {
        $this->intentar(function () use ($cajas) {
            $caja = $cajas->cerrar($this->contado, $this->notasCierre);
            $dif = (float) $caja->diferencia;
            $this->mensaje = 'Corte realizado. '.($dif == 0.0 ? 'La caja cuadró.' : ($dif > 0 ? 'Sobrante de $'.number_format($dif, 2).'.' : 'Faltante de $'.number_format(abs($dif), 2).'.'));
            $this->contado = ['efectivo' => '', 'transferencia' => '', 'tarjeta' => '', 'otro' => ''];
            $this->notasCierre = '';
        }, 'No se pudo cerrar la caja.');
    }
}; ?>

<div class="space-y-6">
    <x-panel.encabezado titulo="Caja y cortes"
        subtitulo="Abre tu caja con el fondo, registra retiros o ingresos de efectivo y haz el corte: esperado contra contado por método de pago." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    @if (! $this->caja)
        <form wire:submit="abrir" class="fp-tarjeta p-5 fp-formulario fp-aparecer max-w-xl space-y-4" data-abrir-caja>
            <h3 class="text-sm font-semibold text-slate-800">Abrir caja</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="block text-slate-600 mb-1">Fondo inicial (efectivo)</span>
                    <input type="number" step="0.01" min="0" wire:model="fondo" class="w-full rounded-lg text-sm">
                    @error('fondo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                </label>
                <label class="text-sm">
                    <span class="block text-slate-600 mb-1">Sucursal</span>
                    <select wire:model="sucursalId" class="w-full rounded-lg text-sm">
                        @foreach ($this->sucursales as $sucursal)
                            <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                        @endforeach
                    </select>
                    @error('sucursal') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="block text-sm">
                <span class="block text-slate-600 mb-1">Notas (opcional)</span>
                <input type="text" wire:model="notasApertura" maxlength="200" class="w-full rounded-lg text-sm">
            </label>
            <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="abrir">Abrir caja</button>
        </form>
    @else
        @php $r = $this->resumen; $etiquetas = \App\Models\Pago::ETIQUETAS_METODO; @endphp
        <div class="flex flex-wrap items-center gap-2 text-sm text-fp-text-muted fp-aparecer">
            <x-ui.insignia-estado variante="success" texto="Caja abierta" />
            desde {{ $this->caja->abierta_at->format('d/m/Y H:i') }} · fondo ${{ number_format((float) $this->caja->fondo_inicial, 2) }}
            · {{ $r['ventas'] }} venta(s) · {{ $r['canceladas'] }} cancelada(s)
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($r['metodos'] as $metodo => $m)
                <x-panel.metrica :titulo="'Esperado en '.mb_strtolower($etiquetas[$metodo] ?? $metodo)" :valor="'$'.number_format($m['esperado'], 2)"
                    :tono="$metodo === 'efectivo' ? 'exito' : 'primario'"
                    :detalle="'Cobros $'.number_format($m['cobros'], 2).' · devoluciones $'.number_format($m['devoluciones'], 2).($metodo === 'efectivo' ? ' · ingresos $'.number_format($m['ingresos'], 2).' · retiros $'.number_format($m['retiros'], 2) : '')"
                    data-esperado="{{ $metodo }}" class="fp-aparecer" />
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <form wire:submit="registrarMovimiento" class="fp-tarjeta p-5 fp-formulario fp-aparecer space-y-3">
                <h3 class="text-sm font-semibold text-slate-800">Retiro o ingreso de efectivo</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <select wire:model="movTipo" class="rounded-lg text-sm">
                        <option value="retiro">Retiro</option>
                        <option value="ingreso">Ingreso</option>
                    </select>
                    <input type="number" step="0.01" min="0.01" wire:model="movMonto" placeholder="Monto" class="rounded-lg text-sm">
                    <input type="text" wire:model="movMotivo" placeholder="Motivo" maxlength="300" class="rounded-lg text-sm">
                </div>
                @error('movMonto') <p class="text-xs text-fp-danger">{{ $message }}</p> @enderror
                @error('movMotivo') <p class="text-xs text-fp-danger">{{ $message }}</p> @enderror
                <button type="submit" class="fp-btn fp-btn-secundario fp-btn-sm">Registrar</button>

                @if ($r['movimientos']->isNotEmpty())
                    <ul class="mt-2 divide-y divide-slate-100 text-sm">
                        @foreach ($r['movimientos'] as $mov)
                            <li class="flex justify-between py-1.5">
                                <span>{{ $mov->tipo === 'retiro' ? 'Retiro' : 'Ingreso' }} · {{ $mov->motivo }}</span>
                                <span class="tabular-nums {{ $mov->tipo === 'retiro' ? 'text-fp-danger' : 'text-emerald-700' }}">{{ $mov->tipo === 'retiro' ? '−' : '+' }}${{ number_format((float) $mov->monto, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </form>

            <form wire:submit="cerrar" class="fp-tarjeta p-5 fp-formulario fp-aparecer space-y-3"
                x-data="{ contado: @entangle('contado'), esperado: @js(array_map(fn ($m) => $m['esperado'], $r['metodos'])),
                          get diferencia() { return Object.keys(this.esperado).reduce((s, k) => s + (parseFloat(this.contado[k]) || 0) - this.esperado[k], 0) } }">
                <h3 class="text-sm font-semibold text-slate-800">Corte de caja</h3>
                <p class="text-xs text-fp-text-muted">Cuenta lo que hay en caja y en cada terminal. La diferencia se calcula sola.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($r['metodos'] as $metodo => $m)
                        <label class="text-sm">
                            <span class="block text-slate-600 mb-1">{{ $etiquetas[$metodo] ?? $metodo }} <span class="text-xs text-fp-text-muted">(esperado ${{ number_format($m['esperado'], 2) }})</span></span>
                            <input type="number" step="0.01" min="0" x-model="contado.{{ $metodo }}" class="w-full rounded-lg text-sm" data-contado="{{ $metodo }}">
                            @error('contado.'.$metodo) <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                <p class="text-sm font-medium transition-colors" :class="Math.abs(diferencia) < 0.005 ? 'text-emerald-700' : (diferencia > 0 ? 'text-amber-700' : 'text-fp-danger')">
                    Diferencia: <span x-text="(diferencia < 0 ? '−$' : '$') + Math.abs(diferencia).toFixed(2)"></span>
                </p>
                <input type="text" wire:model="notasCierre" placeholder="Notas del corte (opcional)" maxlength="250" class="w-full rounded-lg text-sm">
                <button type="submit" class="fp-btn fp-btn-oscuro" wire:confirm="¿Cerrar la caja con estos importes? Ya no se podrá modificar.">Cerrar caja y hacer corte</button>
            </form>
        </div>
    @endif

    <section class="space-y-3">
        <h3 class="text-sm font-semibold text-slate-800">Historial de cortes</h3>
        <x-panel.tabla class="fp-aparecer">
            <x-slot:encabezado>
                <tr>
                    <th class="px-4 py-3 font-medium">Cerrada</th>
                    <th class="px-4 py-3 font-medium">Quién</th>
                    <th class="px-4 py-3 font-medium">Sucursal</th>
                    <th class="px-4 py-3 font-medium text-right">Esperado</th>
                    <th class="px-4 py-3 font-medium text-right">Contado</th>
                    <th class="px-4 py-3 font-medium text-right">Diferencia</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </x-slot:encabezado>
            @forelse ($this->historial as $corte)
                @php $dif = (float) $corte->diferencia; @endphp
                <tr wire:key="corte-{{ $corte->id }}" class="transition-colors hover:bg-fp-page/60">
                    <td class="px-4 py-3 whitespace-nowrap">{{ $corte->cerrada_at?->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">{{ $corte->abiertaPor?->usuario?->nombreVisible() ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $corte->sucursal?->nombre }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">${{ number_format(array_sum($corte->esperado ?? []), 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">${{ number_format(array_sum($corte->contado ?? []), 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium {{ $dif == 0.0 ? 'text-emerald-700' : ($dif > 0 ? 'text-amber-700' : 'text-fp-danger') }}">{{ $dif < 0 ? '−' : '' }}${{ number_format(abs($dif), 2) }}</td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('caja.pdf', $corte->id) }}" class="fp-btn fp-btn-fantasma fp-btn-sm">PDF</a></td>
                </tr>
            @empty
                <x-panel.vacio colspan="7" mensaje="Todavía no hay cortes." />
            @endforelse
        </x-panel.tabla>
        <div>{{ $this->historial->links() }}</div>
    </section>
</div>
