<?php

use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Services\Distribuidora\CupoLineasDistribuidora;
use Livewire\Component;

/**
 * Las líneas del catálogo compartido, de solo lectura (TG-213), marcando
 * cuáles vende esta distribuidora.
 *
 * Activar y desactivar líneas es la pantalla "Mis líneas" (R6), que llama a
 * las acciones de TG-210. Aquí solo se ve el estado y cuántas lleva de su plan.
 */
new class extends Component {
    public function getLineasProperty()
    {
        return Linea::query()->with('campanas')->orderBy('nombre')->get();
    }

    /** Las que esta distribuidora activó, por id de línea. */
    public function getMisLineasProperty()
    {
        return DistribuidoraLinea::query()->get()->keyBy('linea_id');
    }

    public function getCupoProperty(): array
    {
        $cupo = app(CupoLineasDistribuidora::class);

        try {
            return ['activas' => $cupo->activas(), 'limite' => $cupo->limite()];
        } catch (\Throwable) {
            // Sin suscripción activa no hay cupo que mostrar.
            return [];
        }
    }
}; ?>

<div class="space-y-4">
    <x-catalogo.aviso-solo-lectura />

    @if ($this->cupo !== [])
        <div class="rounded-xl border border-slate-200/80 bg-white px-4 py-3 text-sm text-fp-text-muted shadow-sm">
            Líneas activas de tu plan:
            <span class="font-semibold text-slate-900">{{ $this->cupo['activas'] }}/{{ $this->cupo['limite'] }}</span>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-fp-page">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-fp-text-muted">
                    <th class="px-6 py-3">Línea</th>
                    <th class="px-6 py-3">Temporadas</th>
                    <th class="px-6 py-3">En el catálogo</th>
                    <th class="px-6 py-3">¿La vendes?</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->lineas as $linea)
                    @php($mia = $this->misLineas->get($linea->id))
                    <tr>
                        <td class="px-6 py-3.5 font-medium text-slate-900">{{ $linea->nombre }}</td>
                        <td class="px-6 py-3.5 text-slate-600">
                            @forelse ($linea->campanas as $campana)
                                <span class="mr-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                    {{ $campana->nombre }}{{ $campana->estado === 'activa' ? ' · activa' : '' }}
                                </span>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td class="px-6 py-3.5">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $linea->activa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $linea->activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5">
                            @if ($mia?->activa)
                                <span class="rounded-full bg-fp-primary/10 px-2.5 py-1 text-xs font-semibold text-fp-primary">
                                    Sí{{ $mia->es_extra ? ' · extra' : '' }}
                                </span>
                            @else
                                <span class="text-fp-text-muted">No</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-fp-text-muted">
                            Todavía no hay líneas en el catálogo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
