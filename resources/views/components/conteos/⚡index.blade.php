<?php

use App\Models\ConteoInventario;
use App\Models\ConteoInventarioRenglon;
use App\Models\Producto;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Services\Auth\PermisoModuloService;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Inventario\ConteoInventarioService;
use App\Support\MensajeError;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inventario físico: iniciar un conteo (de un modelo o de todo), capturar con
 * el escáner USB (lee el SKU y da Enter) o a mano, ver las diferencias y
 * aplicarlas como ajustes de stock en un solo paso.
 */
new #[Layout('layouts.panel')] #[Title('Inventario físico — FootwearPoint')] class extends Component {
    #[Url(as: 'conteo', except: null)]
    public ?int $conteoId = null;

    public ?string $productoId = '';
    public string $notas = '';
    public string $codigo = '';
    public int $piezas = 1;
    public bool $soloDiferencias = false;

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_unless(app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::INVENTARIO_CONTEO), 403);
    }

    public function getConteoProperty(): ?ConteoInventario
    {
        return $this->conteoId ? ConteoInventario::query()->with(['producto', 'creadoPor.usuario'])->find($this->conteoId) : null;
    }

    public function getRenglonesProperty()
    {
        if (! $this->conteo) {
            return collect();
        }

        return ConteoInventarioRenglon::query()->where('conteo_id', $this->conteo->id)
            ->with(['variante.producto', 'variante.talla', 'variante.color'])
            ->orderByDesc('updated_at')->orderBy('id')->get()
            ->when($this->soloDiferencias, fn ($c) => $c->filter(fn ($r) => $r->contado !== null && $r->diferencia() !== 0));
    }

    public function getResumenProperty(): ?array
    {
        return $this->conteo ? app(ConteoInventarioService::class)->resumen($this->conteo) : null;
    }

    public function getHistorialProperty()
    {
        return ConteoInventario::query()->with('producto')->withCount('renglones')->orderByDesc('id')->limit(20)->get();
    }

    public function getModelosProperty()
    {
        $variantes = StockLocal::query()->select('variante_id');

        return Producto::query()->whereIn('id', Variante::query()->whereIn('id', $variantes)->select('producto_id'))
            ->orderBy('modelo')->limit(500)->get(['id', 'modelo', 'nombre']);
    }

    /** @return array<int, string> producto_id => url */
    public function getFotosProperty(): array
    {
        $ids = $this->renglones->map(fn ($r) => $r->variante?->producto_id)
            ->merge($this->historial->pluck('producto_id'))
            ->push($this->productoId !== '' ? $this->productoId : null)
            ->push($this->conteo?->producto_id);

        return app(ImagenDeProducto::class)->porProducto($ids);
    }

    public function getPuedeAplicarProperty(): bool
    {
        return app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::STOCK_AJUSTAR);
    }

    public function iniciar(ConteoInventarioService $servicio): void
    {
        $this->limpiar();
        try {
            $conteo = $servicio->iniciar($this->productoId !== '' && $this->productoId !== null ? (int) $this->productoId : null, $this->notas ?: null);
            $this->conteoId = $conteo->id;
            $this->mensaje = 'Conteo #'.$conteo->id.' iniciado. Escanea las piezas o captura lo contado.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo iniciar el conteo.');
        }
    }

    public function escanear(ConteoInventarioService $servicio): void
    {
        $this->limpiar();
        try {
            $renglon = $servicio->escanear((int) $this->conteoId, $this->codigo, max(1, (int) $this->piezas));
            $v = $renglon->variante()->with(['producto', 'talla'])->first();
            $this->mensaje = ($v?->producto?->modelo ?? $this->codigo).' talla '.($v?->talla?->valor ?? '—').': '.$renglon->contado.' contadas.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar la lectura.');
        }
        $this->codigo = '';
        $this->piezas = 1;
        $this->dispatch('conteo-escaneado');
    }

    public function guardarContado(int $renglonId, $valor = null): void
    {
        $this->limpiar();
        try {
            app(ConteoInventarioService::class)->fijarContado((int) $this->conteoId, $renglonId, $valor === null || $valor === '' ? null : (int) $valor);
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo guardar lo contado.');
        }
    }

    public function aplicar(ConteoInventarioService $servicio): void
    {
        $this->limpiar();
        try {
            $servicio->aplicar((int) $this->conteoId);
            $this->mensaje = 'Ajustes aplicados. El stock quedó igual a lo contado.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudieron aplicar los ajustes.');
        }
    }

    public function cancelar(ConteoInventarioService $servicio): void
    {
        $this->limpiar();
        try {
            $servicio->cancelar((int) $this->conteoId);
            $this->mensaje = 'Conteo cancelado. No se tocó el stock.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo cancelar.');
        }
    }

    public function salir(): void
    {
        $this->conteoId = null;
        $this->limpiar();
    }

    private function limpiar(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
    }
}; ?>

<div class="space-y-4">
    <x-panel.encabezado titulo="Inventario físico"
        subtitulo="Cuenta lo que hay en tu sucursal con el escáner o a mano, revisa las diferencias y aplícalas al stock en un solo paso." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    @php
        $c = $this->conteo;
    @endphp

    @if (! $c)
        <form wire:submit="iniciar" class="fp-tarjeta p-5 fp-formulario fp-aparecer max-w-2xl space-y-4" data-iniciar-conteo>
            <p class="text-sm font-medium text-slate-800">Nuevo conteo</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm text-slate-600">¿Qué vas a contar?
                    <div class="mt-1 flex items-center gap-2">
                        @if ($productoId !== '' && $productoId !== null)
                            <x-ui.imagen-ampliable :url="$this->fotos[(int) $productoId] ?? null" :alt="$this->modelos->firstWhere('id', (int) $productoId)?->nombre ?? 'Producto'" />
                        @endif
                        <select wire:model.live="productoId" class="w-full rounded-lg text-sm">
                            <option value="">Todo lo que tiene existencia</option>
                            @foreach ($this->modelos as $m)
                                <option value="{{ $m->id }}">{{ $m->modelo }} — {{ $m->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </label>
                <label class="block text-sm text-slate-600">Notas (opcional)
                    <input type="text" wire:model="notas" maxlength="500" class="mt-1 w-full rounded-lg text-sm" placeholder="Ej. conteo de fin de mes">
                </label>
            </div>
            <button type="submit" class="fp-btn fp-btn-primario rounded-xl" wire:loading.attr="disabled">Iniciar conteo</button>
        </form>

        <div class="fp-tarjeta overflow-x-auto fp-aparecer">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Conteo</th><th class="px-4 py-2">Alcance</th><th class="px-4 py-2">Renglones</th><th class="px-4 py-2">Estado</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->historial as $h)
                        <tr wire:key="h-{{ $h->id }}" class="transition-colors hover:bg-slate-50">
                            <td class="px-4 py-2">#{{ $h->id }} <span class="block text-xs text-slate-500">{{ $h->created_at?->format('d/m/Y H:i') }}</span></td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    @if ($h->producto_id)
                                        <x-ui.imagen-ampliable :url="$this->fotos[(int) $h->producto_id] ?? null" :alt="$h->producto?->nombre ?? 'Producto'" />
                                    @endif
                                    <span>{{ $h->producto?->modelo ?? 'Todo el inventario' }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ $h->renglones_count }}</td>
                            <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $h->estado === 'abierto' ? 'bg-fp-badge-warning-bg text-fp-badge-warning-fg' : ($h->estado === 'aplicado' ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-slate-100 text-slate-500') }}">{{ ucfirst($h->estado) }}</span></td>
                            <td class="px-4 py-2 text-right"><button type="button" wire:click="$set('conteoId', {{ $h->id }})" class="fp-enlace text-sm font-medium">{{ $h->estado === 'abierto' ? 'Continuar' : 'Ver' }}</button></td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="Todavía no hay conteos" />
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        @php
            $r = $this->resumen;
        @endphp
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="salir" class="fp-btn fp-btn-fantasma rounded-xl">← Conteos</button>
            @if ($c->producto_id)
                <x-ui.imagen-ampliable :url="$this->fotos[(int) $c->producto_id] ?? null" :alt="$c->producto?->nombre ?? 'Producto'" />
            @endif
            <p class="text-sm text-slate-700">Conteo #{{ $c->id }} · {{ $c->producto?->modelo ?? 'Todo el inventario' }} · {{ ucfirst($c->estado) }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-resumen-conteo>
            <x-panel.metrica titulo="Contados" :valor="$r['contados'].' de '.$r['renglones']" :progreso="$r['renglones'] ? $r['contados'] * 100 / $r['renglones'] : 0" class="fp-aparecer" />
            <x-panel.metrica titulo="Con diferencia" :valor="$r['con_diferencia']" :tono="$r['con_diferencia'] ? 'aviso' : 'exito'" class="fp-aparecer" />
            <x-panel.metrica titulo="Sobrantes" :valor="'+'.$r['sobrantes'].' pzas'" tono="exito" class="fp-aparecer" />
            <x-panel.metrica titulo="Faltantes" :valor="'−'.$r['faltantes'].' pzas'" :tono="$r['faltantes'] ? 'peligro' : 'neutral'" class="fp-aparecer" />
        </div>

        @if ($c->estaAbierto())
            <form wire:submit="escanear" class="fp-tarjeta p-4 fp-formulario fp-aparecer flex flex-wrap items-end gap-3"
                x-data x-init="$refs.codigo.focus()" x-on:conteo-escaneado.window="$nextTick(() => $refs.codigo.focus())" data-escaner>
                <label class="block flex-1 text-sm text-slate-600">Código (SKU) — escanea y da Enter
                    <input x-ref="codigo" type="text" wire:model="codigo" autocomplete="off" class="mt-1 w-full rounded-lg font-mono text-sm" placeholder="Escanea la etiqueta">
                </label>
                <label class="block w-24 text-sm text-slate-600">Piezas
                    <input type="number" min="1" max="9999" wire:model="piezas" class="mt-1 w-full rounded-lg text-sm">
                </label>
                <button type="submit" class="fp-btn fp-btn-primario rounded-xl">Sumar</button>
            </form>
        @endif

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" wire:model.live="soloDiferencias" class="rounded"> Solo diferencias</label>
            @if ($c->estaAbierto())
                <div class="flex gap-2">
                    <button type="button" wire:click="cancelar" wire:confirm="¿Cancelar este conteo? No se toca el stock." class="fp-btn fp-btn-fantasma rounded-xl">Cancelar conteo</button>
                    @if ($this->puedeAplicar)
                        <button type="button" wire:click="aplicar" wire:loading.attr="disabled" data-aplicar-conteo
                            wire:confirm="¿Aplicar {{ $r['con_diferencia'] }} ajustes? El stock de lo contado quedará igual a lo contado; lo no contado no cambia."
                            class="fp-btn fp-btn-primario rounded-xl">Aplicar ajustes</button>
                    @else
                        <span class="text-xs text-slate-500">Aplicar requiere el permiso de ajustes de stock.</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="fp-tarjeta overflow-x-auto fp-aparecer">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Modelo</th><th class="px-4 py-2">Talla · color</th><th class="px-4 py-2">SKU</th><th class="px-4 py-2 text-right">Esperado</th><th class="px-4 py-2 text-right">Contado</th><th class="px-4 py-2 text-right">Diferencia</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->renglones as $ren)
                        @php
                            $dif = $ren->diferencia();
                        @endphp
                        <tr wire:key="ren-{{ $ren->id }}" class="transition-colors hover:bg-slate-50">
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <x-ui.imagen-ampliable :url="$this->fotos[(int) ($ren->variante?->producto_id ?? 0)] ?? null" :alt="$ren->variante?->producto?->nombre ?? 'Producto'" />
                                    <div>{{ $ren->variante?->producto?->modelo }} <span class="block text-xs text-slate-500">{{ $ren->variante?->producto?->nombre }}</span></div>
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ $ren->variante?->talla?->valor }} · {{ $ren->variante?->nombre_color_comercial ?? $ren->variante?->color?->nombre }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $ren->variante?->sku }}</td>
                            <td class="px-4 py-2 text-right">{{ $ren->esperado }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($c->estaAbierto())
                                    <input type="number" min="0" class="w-20 rounded-lg text-right text-sm" value="{{ $ren->contado }}" placeholder="—"
                                        x-on:change="$wire.guardarContado({{ $ren->id }}, $event.target.value)" data-contado="{{ $ren->id }}">
                                @else
                                    {{ $ren->contado ?? '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-medium {{ $dif === null ? 'text-slate-400' : ($dif > 0 ? 'text-emerald-700' : ($dif < 0 ? 'text-fp-danger' : 'text-slate-500')) }}">
                                {{ $dif === null ? 'sin contar' : ($dif > 0 ? '+'.$dif : $dif) }}
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="6" mensaje="Sin renglones. Escanea la primera pieza." />
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
