<?php

use App\Services\Comision\ComisionesService;
use App\Support\MensajeError;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Comisiones y metas de clientes mayoristas: ranking del mes, comisión sobre
 * pedidos pagados, avance contra la meta. La administración fija el % por
 * omisión, el % propio de cada quien y las metas del mes.
 */
new #[Layout('layouts.panel')] #[Title('Comisiones y metas — FootwearPoint')] class extends Component {
    #[Url(except: '')]
    public string $periodo = '';

    public string $porcentajeDefault = '0';
    public array $porcentajes = [];
    public array $metas = [];

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(ComisionesService $servicio): void
    {
        $servicio->exigirVer();
        $this->periodo = ComisionesService::periodoValido($this->periodo ?: null);
        $this->porcentajeDefault = number_format($servicio->porcentajeDefault(), 2, '.', '');
        $this->cargarEdicion();
    }

    public function updatedPeriodo(): void
    {
        $this->periodo = ComisionesService::periodoValido($this->periodo ?: null);
        $this->cargarEdicion();
    }

    public function getFilasProperty(): array
    {
        return app(ComisionesService::class)->reporte($this->periodo);
    }

    public function getEsAdminProperty(): bool
    {
        return ComisionesService::esAdministracion();
    }

    public function guardarDefault(ComisionesService $servicio): void
    {
        $this->accion(fn () => $servicio->fijarPorcentajeDefault((float) $this->porcentajeDefault), 'Porcentaje por omisión guardado.');
    }

    public function guardarFila(int $afiliacionId, ComisionesService $servicio): void
    {
        $this->accion(function () use ($afiliacionId, $servicio) {
            $pct = trim((string) ($this->porcentajes[$afiliacionId] ?? ''));
            $meta = trim((string) ($this->metas[$afiliacionId] ?? ''));
            $servicio->fijarPorcentaje($afiliacionId, $pct === '' ? null : (float) $pct);
            $servicio->fijarMeta($afiliacionId, $this->periodo, $meta === '' ? null : (float) $meta);
        }, 'Cambios guardados.');
    }

    private function accion(callable $hacer, string $exito): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        try {
            $hacer();
            $this->mensaje = $exito;
            $this->cargarEdicion();
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo guardar.');
        }
    }

    private function cargarEdicion(): void
    {
        $this->porcentajes = [];
        $this->metas = [];
        foreach ($this->filas as $f) {
            $this->porcentajes[$f['afiliacion_id']] = $f['porcentaje_propio'] ? number_format($f['porcentaje'], 2, '.', '') : '';
            $this->metas[$f['afiliacion_id']] = $f['meta'] !== null ? number_format($f['meta'], 2, '.', '') : '';
        }
    }
}; ?>

<div class="space-y-4">
    <x-panel.encabezado titulo="Comisiones y metas"
        subtitulo="Comisión sobre los pedidos del mes ya pagados por completo. El ranking ordena por venta pagada." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    @php
        $filas = $this->filas;
        $mxn = fn ($n) => '$'.number_format((float) $n, 2);
        $totalVenta = array_sum(array_column($filas, 'venta_pagada'));
        $totalComision = array_sum(array_column($filas, 'comision'));
        $conMeta = array_filter($filas, fn ($f) => $f['meta'] !== null);
        $cumplidas = count(array_filter($conMeta, fn ($f) => $f['avance'] >= 100));
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <label class="block text-sm text-slate-600">Mes
            <input type="month" wire:model.live="periodo" class="mt-1 rounded-lg text-sm" data-periodo>
        </label>
        @if ($this->esAdmin)
            <form wire:submit="guardarDefault" class="flex items-end gap-2">
                <label class="block text-sm text-slate-600">% por omisión de la distribuidora
                    <input type="number" step="0.01" min="0" max="100" wire:model="porcentajeDefault" class="mt-1 w-28 rounded-lg text-sm">
                </label>
                <button type="submit" class="fp-btn fp-btn-fantasma rounded-xl">Guardar</button>
            </form>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-panel.metrica titulo="Venta pagada del mes" :valor="$mxn($totalVenta)" class="fp-aparecer" />
        <x-panel.metrica titulo="Comisiones a pagar" :valor="$mxn($totalComision)" tono="exito" class="fp-aparecer" />
        <x-panel.metrica titulo="Metas cumplidas" :valor="$cumplidas.' de '.count($conMeta)" :progreso="count($conMeta) ? $cumplidas * 100 / count($conMeta) : 0" tono="aviso" class="fp-aparecer" />
    </div>

    <div class="fp-tarjeta overflow-x-auto fp-aparecer">
        <x-ui.cargando objetivo="periodo" />
        <table class="fp-tabla min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2">#</th><th class="px-4 py-2">Cliente mayorista</th><th class="px-4 py-2 text-right">Pedidos pagados</th>
                    <th class="px-4 py-2 text-right">Venta pagada</th><th class="px-4 py-2 text-right">%</th><th class="px-4 py-2 text-right">Comisión</th>
                    <th class="px-4 py-2">Meta del mes</th>
                    @if ($this->esAdmin)<th></th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($filas as $f)
                    <tr wire:key="com-{{ $f['afiliacion_id'] }}" class="transition-colors hover:bg-slate-50" data-ranking="{{ $f['lugar'] }}">
                        <td class="px-4 py-2">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold {{ $f['lugar'] <= 3 && $f['venta_pagada'] > 0 ? 'bg-fp-primary text-white' : 'bg-slate-100 text-slate-500' }}">{{ $f['lugar'] }}</span>
                        </td>
                        <td class="px-4 py-2 font-medium text-slate-800">{{ $f['nombre'] }} @if ($f['estado'] !== 'activo')<span class="text-xs text-slate-400">({{ $f['estado'] }})</span>@endif</td>
                        <td class="px-4 py-2 text-right">{{ $f['pedidos_pagados'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $mxn($f['venta_pagada']) }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($this->esAdmin)
                                <input type="number" step="0.01" min="0" max="100" wire:model="porcentajes.{{ $f['afiliacion_id'] }}" placeholder="{{ number_format($f['porcentaje'], 2) }}" class="w-20 rounded-lg text-right text-sm" title="Vacío = usa el % de la distribuidora">
                            @else
                                {{ number_format($f['porcentaje'], 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-medium text-emerald-700">{{ $mxn($f['comision']) }}</td>
                        <td class="px-4 py-2 min-w-48">
                            @if ($this->esAdmin)
                                <input type="number" step="0.01" min="0" wire:model="metas.{{ $f['afiliacion_id'] }}" placeholder="Sin meta" class="w-28 rounded-lg text-sm">
                            @endif
                            @if ($f['meta'] !== null)
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full transition-all duration-700 {{ $f['avance'] >= 100 ? 'bg-fp-badge-success-fg' : 'bg-fp-primary' }}" style="width: {{ min(100, $f['avance']) }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500">{{ $f['avance'] }}% de {{ $mxn($f['meta']) }}</span>
                            @endif
                        </td>
                        @if ($this->esAdmin)
                            <td class="px-4 py-2 text-right"><button type="button" wire:click="guardarFila({{ $f['afiliacion_id'] }})" class="fp-enlace text-sm font-medium">Guardar</button></td>
                        @endif
                    </tr>
                @empty
                    <x-panel.vacio colspan="8" mensaje="No hay clientes mayoristas" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
