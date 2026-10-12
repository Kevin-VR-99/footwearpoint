<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\CupoLineasDistribuidora;
use App\Services\Distribuidora\DesactivarLineaDistribuidoraAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Mis líneas — FootwearPoint')] class extends Component {
    public ?int $lineaPorConfirmar = null;
    public string $avisoCargo = '';
    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    public function activar(int $lineaId): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            app(ActivarLineaDistribuidoraAction::class)->ejecutar($lineaId);
            $this->mensaje = 'Línea activada. Ya puedes ofrecer sus productos.';
        } catch (OperacionInvalidaException $e) {
            if (str_contains($e->getMessage(), 'Confirma')) {
                $this->lineaPorConfirmar = $lineaId;
                $this->avisoCargo = $e->getMessage();

                return;
            }

            $this->errorMsg = $e->getMessage();
        }
    }

    public function confirmarExtra(): void
    {
        if (! $this->lineaPorConfirmar) {
            return;
        }

        try {
            app(ActivarLineaDistribuidoraAction::class)->ejecutar($this->lineaPorConfirmar, true);
            $this->mensaje = 'Línea activada con cargo extra.';
            $this->lineaPorConfirmar = null;
            $this->avisoCargo = '';
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function cancelarExtra(): void
    {
        $this->lineaPorConfirmar = null;
        $this->avisoCargo = '';
    }

    public function desactivar(int $lineaId): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            app(DesactivarLineaDistribuidoraAction::class)->ejecutar($lineaId);
            $this->mensaje = 'Línea desactivada. Su lugar del plan quedó libre.';
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        }
    }

    public function getLineasProperty()
    {
        return Linea::query()->where('activa', true)->orderBy('nombre')->get();
    }

    public function getMiasProperty()
    {
        return DistribuidoraLinea::query()->get()->keyBy('linea_id');
    }

    public function getCupoProperty(): array
    {
        $cupo = app(CupoLineasDistribuidora::class);

        try {
            return [
                'activas' => $cupo->activas(),
                'limite' => $cupo->limite(),
                'incluidas' => $cupo->incluidasEnElPlan(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Catálogo</p>
        <h1 class="mt-1 text-2xl font-bold text-fp-sidebar">Mis líneas</h1>
        <p class="mt-1 text-sm text-slate-500">Elige del catálogo maestro las líneas que vende tu distribuidora. No se crean líneas aquí.</p>
    </div>

    @if ($this->cupo !== [])
        <div class="rounded-xl border bg-white px-4 py-3 text-sm text-slate-600">
            Líneas de tu plan: <span class="font-semibold text-slate-900">{{ $this->cupo['activas'] }}/{{ $this->cupo['limite'] }}</span>
            <span class="text-slate-400">({{ $this->cupo['incluidas'] }} incluidas)</span>
        </div>
    @endif

    @if ($mensaje !== '')
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ $mensaje }}</div>
    @endif
    @if ($errorMsg !== '')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ $errorMsg }}</div>
    @endif

    @if ($lineaPorConfirmar)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p>{{ $avisoCargo }}</p>
            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="confirmarExtra" class="rounded-lg bg-fp-primary px-3 py-2 text-sm text-white">Confirmar y activar</button>
                <button type="button" wire:click="cancelarExtra" class="rounded-lg px-3 py-2 text-sm text-slate-600">Cancelar</button>
            </div>
        </div>
    @endif

    <table class="w-full rounded-2xl bg-white text-sm">
        <thead>
            <tr class="text-left text-slate-500">
                <th class="p-3">Línea</th>
                <th class="p-3">¿La vendes?</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($this->lineas as $linea)
                @php($mia = $this->mias->get($linea->id))
                <tr class="border-t">
                    <td class="p-3 font-medium">{{ $linea->nombre }}</td>
                    <td class="p-3">
                        @if ($mia?->activa)
                            Sí{{ $mia->es_extra ? ' · extra' : '' }}
                        @else
                            No
                        @endif
                    </td>
                    <td class="p-3 text-right">
                        @if ($mia?->activa)
                            <button type="button" wire:click="desactivar({{ $linea->id }})" class="text-sm text-red-600">Desactivar</button>
                        @else
                            <button type="button" wire:click="activar({{ $linea->id }})" class="rounded-lg bg-fp-primary px-3 py-1.5 text-sm text-white">Activar</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>