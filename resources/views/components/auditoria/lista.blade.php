@props([
    'registros',
    'areas',
    'acciones',
    'entidades',
    'usuarios',
    'detalleId' => null,
])

@php
    use App\Support\Auditoria\EtiquetasAuditoria;
@endphp

{{-- TG-267 (U7): filtros y tabla compartidos por la auditoría de la distribuidora y la del admin general. --}}
<div class="mb-4 fp-tarjeta p-4">
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[10rem] flex-1">
            <label class="mb-1 block text-xs text-fp-text-muted">Buscar</label>
            <x-ui.buscador wire:model.live.debounce.300ms="buscar"
                placeholder="Buscar por usuario, acción o IP…" etiqueta="Buscar en la auditoría" />
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Área</label>
            <select wire:model.live="filtro_area" class="rounded-lg text-sm">
                <option value="">Todas</option>
                @foreach ($areas as $area)
                    <option value="{{ $area }}">{{ $area }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Acción</label>
            <select wire:model.live="filtro_accion" class="rounded-lg text-sm">
                <option value="">Todas</option>
                @foreach ($acciones as $accion)
                    <option value="{{ $accion }}">{{ EtiquetasAuditoria::etiqueta($accion) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Entidad</label>
            <select wire:model.live="filtro_entidad" class="rounded-lg text-sm">
                <option value="">Todas</option>
                @foreach ($entidades as $entidad)
                    <option value="{{ $entidad }}">{{ $entidad }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Usuario</label>
            <select wire:model.live="filtro_usuario" class="rounded-lg text-sm">
                <option value="">Todos</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->id }}">{{ $usuario->nombreVisible() ?: $usuario->email }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Desde</label>
            <input type="date" wire:model.live="filtro_desde" class="rounded-lg text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-xs text-fp-text-muted">Hasta</label>
            <input type="date" wire:model.live="filtro_hasta" class="rounded-lg text-sm" />
        </div>
        <x-panel.boton variante="secundario" wire:click="limpiarFiltros">Limpiar</x-panel.boton>
    </div>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="fp-tabla min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-3 font-medium">Fecha</th>
                    <th class="px-4 py-3 font-medium">Usuario</th>
                    <th class="px-4 py-3 font-medium">Acción</th>
                    <th class="px-4 py-3 font-medium">Entidad</th>
                    <th class="px-4 py-3 font-medium">IP</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($registros as $row)
                    <tr class="hover:bg-slate-50/80" wire:key="aud-{{ $row->id }}">
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">
                            {{ optional($row->created_at)->format('d/m/Y H:i:s') }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ $row->usuario?->nombreVisible() ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $row->usuario?->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-[11px] uppercase tracking-wide text-fp-text-muted">{{ EtiquetasAuditoria::area($row->accion) }}</div>
                            <span class="inline-flex rounded-full bg-fp-badge-info-bg px-2 py-0.5 text-xs font-medium text-fp-primary">
                                {{ EtiquetasAuditoria::etiqueta($row->accion) }}
                            </span>
                            <div class="mt-0.5 font-mono text-[11px] text-slate-400">{{ $row->accion }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            {{ $row->entidad_tipo }}
                            @if ($row->entidad_id)
                                <span class="text-slate-400">#{{ $row->entidad_id }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs tabular-nums text-slate-500">{{ $row->ip_origen ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="toggleDetalle({{ $row->id }})"
                                class="text-xs font-medium fp-enlace">
                                {{ $detalleId === $row->id ? 'Ocultar' : 'Detalle' }}
                            </button>
                        </td>
                    </tr>
                    @if ($detalleId === $row->id)
                        <tr class="bg-slate-50/60" wire:key="aud-det-{{ $row->id }}">
                            <td colspan="6" class="px-4 py-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Antes</p>
                                        <pre class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">{{ $row->datos_previos ? json_encode($row->datos_previos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                    </div>
                                    <div>
                                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Después</p>
                                        <pre class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">{{ $row->datos_nuevos ? json_encode($row->datos_nuevos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-slate-500">
                            No hay registros de auditoría con estos filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($registros->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $registros->links() }}
        </div>
    @endif
</div>
