<?php

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Mercado Pago — Admin')] class extends Component
{
    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        setPermissionsTeamId(0);

        if (! Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    /** @return array{configurada: bool, consultada: bool, id?: string, nickname?: ?string, email?: ?string, nombre?: ?string} */
    public function getCuentaProperty(): array
    {
        return app(TokenMercadoPagoPlataforma::class)->identidad();
    }

    /** @return array{mes: float, total: float, pendiente: float, pendientes: int, por_plan: \Illuminate\Support\Collection, recientes: \Illuminate\Support\Collection} */
    public function getIngresosProperty(): array
    {
        $base = Pago::query()->where('pagos.tipo', 'suscripcion');
        $aplicados = (clone $base)->where('pagos.estado', 'aplicado');

        $porPlan = (clone $aplicados)
            ->leftJoin('suscripciones', 'suscripciones.id', '=', 'pagos.suscripcion_id')
            ->leftJoin('planes_suscripcion', 'planes_suscripcion.id', '=', 'suscripciones.plan_id')
            ->groupBy('planes_suscripcion.id', 'planes_suscripcion.nombre')
            ->selectRaw('planes_suscripcion.nombre as plan, COUNT(*) as cobros, SUM(pagos.monto) as total')
            ->orderByDesc('total')
            ->get();

        $recientes = Pago::query()
            ->where('pagos.tipo', 'suscripcion')
            ->whereIn('pagos.estado', ['aplicado', 'pendiente'])
            ->with('suscripcion.plan')
            ->latest('pagos.id')
            ->limit(20)
            ->get();

        $nombres = Distribuidora::query()
            ->whereIn('id', $recientes->pluck('distribuidora_id')->filter()->unique())
            ->pluck('nombre_comercial', 'id');

        $recientes->each(function (Pago $pago) use ($nombres) {
            $pago->setAttribute('nombre_distribuidora', $nombres[$pago->distribuidora_id] ?? '—');
        });

        return [
            'mes' => round((float) (clone $aplicados)->where('pagos.fecha_pago', '>=', now()->startOfMonth())->sum('pagos.monto'), 2),
            'total' => round((float) (clone $aplicados)->sum('pagos.monto'), 2),
            'pendiente' => round((float) (clone $base)->where('pagos.estado', 'pendiente')->sum('pagos.monto'), 2),
            'pendientes' => (int) (clone $base)->where('pagos.estado', 'pendiente')->count(),
            'por_plan' => $porPlan,
            'recientes' => $recientes,
        ];
    }
};
?>

<div>
    <x-panel.encabezado titulo="Mercado Pago" class="mb-6">
        <p class="mt-1 text-sm text-fp-text-muted">Cuenta que cobra las mensualidades y lo que ya entró por los planes.</p>
    </x-panel.encabezado>

    @php($cuenta = $this->cuenta)
    <section class="fp-tarjeta mb-6 p-5" data-cuenta-mp>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-fp-text-muted">Cuenta activa</h2>
        @if (! $cuenta['configurada'])
            <p class="mt-2 text-slate-700">No hay una cuenta de Mercado Pago configurada para cobrar las mensualidades.</p>
        @elseif (! $cuenta['consultada'])
            <p class="mt-2 text-slate-700">La cuenta está configurada, pero Mercado Pago no respondió. Los ingresos de abajo sí se muestran.</p>
        @else
            <p class="mt-2 text-lg font-semibold text-fp-sidebar">{{ $cuenta['nombre'] ?? 'Cuenta de FootwearPoint' }}</p>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-fp-text-muted">Correo</dt>
                    <dd>{{ $cuenta['email'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-fp-text-muted">Usuario</dt>
                    <dd>{{ $cuenta['nickname'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-fp-text-muted">Id</dt>
                    <dd>{{ $cuenta['id'] }}</dd>
                </div>
            </dl>
        @endif
    </section>

    @php($ingresos = $this->ingresos)
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-panel.metrica titulo="Cobrado este mes" :valor="'$'.number_format($ingresos['mes'], 2)" detalle="Mensualidades aplicadas" />
        <x-panel.metrica titulo="Cobrado en total" :valor="'$'.number_format($ingresos['total'], 2)" tono="exito" detalle="Desde el primer pago" />
        <x-panel.metrica titulo="Por cobrar" :valor="'$'.number_format($ingresos['pendiente'], 2)" tono="neutral"
            :detalle="$ingresos['pendientes'].' enlace'.($ingresos['pendientes'] === 1 ? '' : 's').' pendiente'.($ingresos['pendientes'] === 1 ? '' : 's')" />
    </div>

    <section class="fp-tarjeta fp-tabla mb-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-fp-sidebar">Por plan</h2>
        @if ($ingresos['por_plan']->isEmpty())
            <p class="px-4 py-6 text-sm text-slate-600">Todavía no hay mensualidades cobradas.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Cobros</th>
                        <th class="px-4 py-3 font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingresos['por_plan'] as $fila)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ $fila->plan ?: 'Sin plan' }}</td>
                            <td class="px-4 py-3">{{ $fila->cobros }}</td>
                            <td class="px-4 py-3">${{ number_format((float) $fila->total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="fp-tarjeta fp-tabla overflow-hidden">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-fp-sidebar">Últimos movimientos</h2>
        @if ($ingresos['recientes']->isEmpty())
            <p class="px-4 py-6 text-sm text-slate-600">No hay mensualidades aplicadas ni enlaces pendientes.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-3 font-medium">Fecha</th>
                        <th class="px-4 py-3 font-medium">Distribuidora</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Estado</th>
                        <th class="px-4 py-3 font-medium">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingresos['recientes'] as $pago)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ ($pago->fecha_pago ?? $pago->created_at)?->timezone(config('app.timezone'))->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $pago->nombre_distribuidora }}</td>
                            <td class="px-4 py-3">{{ $pago->suscripcion?->plan?->nombre ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $pago->estado === 'aplicado' ? 'Cobrado' : 'Pendiente' }}</td>
                            <td class="px-4 py-3">${{ number_format((float) $pago->monto, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
