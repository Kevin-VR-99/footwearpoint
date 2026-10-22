<?php

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Services\Auth\PermisoModuloService;
use App\Services\Cliente\HistorialClienteAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vista 360 del cliente: pedidos, ventas, pagos, vales, saldo por cobrar y
 * cambios en una sola pantalla. Sin cliente elegido, un buscador.
 * Permiso fino clientes.ver (también en la ruta).
 */
new #[Layout('layouts.panel')] #[Title('Historial de clientes — FootwearPoint')] class extends Component {
    #[Locked]
    public ?string $tipo = null;
    #[Locked]
    public ?int $clienteId = null;

    #[Url(except: '')]
    public string $buscar = '';

    public string $pestana = 'pedidos';

    public function mount(?string $tipo = null, ?int $id = null): void
    {
        abort_unless(app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::CLIENTES_VER), 403);
        abort_if($tipo !== null && ! in_array($tipo, HistorialClienteAction::TIPOS, true), 404);
        $this->tipo = $tipo;
        $this->clienteId = $id;
    }

    public function getDatosProperty(): ?array
    {
        return $this->tipo && $this->clienteId ? app(HistorialClienteAction::class)->ejecutar($this->tipo, $this->clienteId) : null;
    }

    public function getResultadosProperty(): array
    {
        $t = '%'.addcslashes(trim($this->buscar), '%_\\').'%';
        $directos = ClienteDirecto::query()
            ->when(trim($this->buscar) !== '', fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'like', $t)->orWhere('telefono', 'like', $t)->orWhere('email', 'like', $t)->orWhereHas('usuario', fn ($u) => $u->where('email', 'like', $t))))
            ->orderBy('nombre')->limit(25)->get(['id', 'nombre', 'telefono', 'estado'])
            ->map(fn ($c) => ['tipo' => 'cliente_directo', 'id' => $c->id, 'nombre' => $c->nombre, 'telefono' => $c->telefono, 'estado' => $c->estado, 'etiqueta' => 'Cliente directo']);
        $mayoristas = RevendedorDistribuidora::query()->with('revendedor')
            ->when(trim($this->buscar) !== '', fn ($q) => $q->whereHas('revendedor', fn ($w) => $w->where('nombre', 'like', $t)->orWhere('telefono', 'like', $t)->orWhere('email', 'like', $t)->orWhereHas('usuario', fn ($u) => $u->where('email', 'like', $t))))
            ->limit(25)->get()
            ->map(fn ($a) => ['tipo' => 'revendedor', 'id' => $a->id, 'nombre' => $a->revendedor?->nombre, 'telefono' => $a->revendedor?->telefono, 'estado' => $a->estado, 'etiqueta' => 'Cliente mayorista']);

        return $directos->concat($mayoristas)->sortBy('nombre')->values()->all();
    }
}; ?>

<div class="space-y-4">
    @php
        $d = $this->datos;
        $mxn = fn ($n) => '$'.number_format((float) $n, 2);
    @endphp

    @if (! $d)
        <x-panel.encabezado titulo="Historial de clientes"
            subtitulo="Busca a un cliente para ver de un vistazo sus pedidos, pagos, vales, saldo por cobrar y cambios." />

        <div class="fp-tarjeta p-4 fp-aparecer">
            <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Nombre, teléfono o correo" etiqueta="Buscar cliente" class="w-full sm:w-96" />
        </div>

        <div class="fp-tarjeta overflow-x-auto fp-aparecer">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Cliente</th><th class="px-4 py-2">Tipo</th><th class="px-4 py-2">Teléfono</th><th class="px-4 py-2">Estado</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->resultados as $r)
                        <tr wire:key="r-{{ $r['tipo'] }}-{{ $r['id'] }}" class="transition-colors hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium text-slate-800">{{ $r['nombre'] }}</td>
                            <td class="px-4 py-2 text-xs text-slate-500">{{ $r['etiqueta'] }}</td>
                            <td class="px-4 py-2">{{ $r['telefono'] }}</td>
                            <td class="px-4 py-2 text-xs">{{ ucfirst($r['estado']) }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('clientes.historial.show', [$r['tipo'], $r['id']]) }}" wire:navigate class="fp-enlace text-sm font-medium">Ver historial</a>
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="No encontramos clientes con esa búsqueda" />
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <x-panel.encabezado :titulo="$d['perfil']['nombre']" :subtitulo="$d['perfil']['tipo_etiqueta'].' · '.($d['perfil']['telefono'] ?: 'sin teléfono').' · '.($d['perfil']['email'] ?: 'sin correo').' · '.ucfirst($d['perfil']['estado']).($d['perfil']['con_app'] ? ' · usa la app' : '')">
            <x-slot:acciones>
                <a href="{{ route('clientes.historial') }}" wire:navigate class="fp-btn fp-btn-fantasma rounded-xl">← Otros clientes</a>
            </x-slot:acciones>
        </x-panel.encabezado>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-resumen-cliente>
            <x-panel.metrica titulo="Comprado" :valor="$mxn($d['resumen']['comprado'])" :detalle="$d['resumen']['pedidos'].' pedidos · '.$d['resumen']['ventas'].' ventas'" class="fp-aparecer" />
            <x-panel.metrica titulo="Pagado" :valor="$mxn($d['resumen']['pagado'])" tono="exito" detalle="Cobros menos reembolsos" class="fp-aparecer" />
            <x-panel.metrica titulo="Saldo por cobrar" :valor="$mxn($d['resumen']['saldo_por_cobrar'])" :tono="$d['resumen']['saldo_por_cobrar'] > 0 ? 'aviso' : 'neutral'" :detalle="count($d['pendientes']).' pedidos con saldo'" data-saldo="{{ $d['resumen']['saldo_por_cobrar'] }}" class="fp-aparecer" />
            <x-panel.metrica titulo="Vales disponibles" :valor="$mxn($d['resumen']['vales_saldo'])" :detalle="$d['resumen']['cambios_abiertos'].' cambios en curso'" class="fp-aparecer" />
        </div>

        <div class="fp-tarjeta p-4 fp-aparecer">
            <div class="flex flex-wrap gap-2 border-b border-slate-100 pb-3">
                @foreach (['pedidos' => 'Pedidos', 'ventas' => 'Mostrador', 'pagos' => 'Pagos', 'vales' => 'Vales', 'cambios' => 'Cambios', 'pendientes' => 'Por cobrar'] as $clave => $texto)
                    @if ($clave !== 'ventas' || $d['tipo'] === 'cliente_directo')
                        <button type="button" wire:click="$set('pestana', '{{ $clave }}')"
                            class="rounded-full px-3 py-1 text-sm transition {{ $pestana === $clave ? 'bg-fp-primary text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                            {{ $texto }} <span class="text-xs opacity-70">{{ count($d[$clave]) }}</span>
                        </button>
                    @endif
                @endforeach
            </div>

            <div class="mt-3 overflow-x-auto fp-aparecer" wire:key="pestana-{{ $pestana }}">
                <table class="fp-tabla min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($d[$pestana] as $f)
                            <tr class="transition-colors hover:bg-slate-50">
                                @switch($pestana)
                                    @case('pedidos')
                                        <td class="py-2"><a href="{{ route('pedidos.show', $f['id']) }}" wire:navigate class="fp-enlace">{{ $f['folio'] }}</a></td>
                                        <td class="py-2 text-xs text-slate-500">{{ $f['fecha'] }}</td><td class="py-2">{{ $f['estado'] }}</td><td class="py-2 text-right">{{ $mxn($f['total']) }}</td>
                                        @break
                                    @case('ventas')
                                        <td class="py-2">{{ $f['folio'] }}</td><td class="py-2 text-xs text-slate-500">{{ $f['fecha'] }}</td><td class="py-2">{{ ucfirst($f['estado']) }}</td><td class="py-2 text-right">{{ $mxn($f['total']) }}</td>
                                        @break
                                    @case('pagos')
                                        <td class="py-2">{{ $f['folio'] }}</td><td class="py-2 text-xs text-slate-500">{{ $f['fecha'] }}</td>
                                        <td class="py-2">{{ \App\Models\Pago::etiquetaTipo($f['tipo']) }} · {{ \App\Models\Pago::etiquetaMetodo($f['metodo']) }}</td>
                                        <td class="py-2 text-right {{ $f['direccion'] === 'salida' ? 'text-fp-danger' : '' }}">{{ $f['direccion'] === 'salida' ? '−' : '' }}{{ $mxn($f['monto']) }}</td>
                                        @break
                                    @case('vales')
                                        <td class="py-2">{{ $f['folio'] }}</td><td class="py-2 text-xs text-slate-500">Vence {{ $f['vence'] ?? '—' }}</td><td class="py-2">{{ ucfirst($f['estado']) }}</td>
                                        <td class="py-2 text-right">{{ $mxn($f['saldo']) }} <span class="text-xs text-slate-400">de {{ $mxn($f['original']) }}</span></td>
                                        @break
                                    @case('cambios')
                                        <td class="py-2">#{{ $f['id'] }} {{ $f['producto'] }}</td><td class="py-2 text-xs text-slate-500">{{ $f['fecha'] }}</td><td class="py-2">{{ $f['estado'] }}</td><td class="py-2 text-xs text-slate-500">{{ $f['motivo'] }}</td>
                                        @break
                                    @case('pendientes')
                                        <td class="py-2">{{ $f['folio'] }}</td><td class="py-2 text-xs text-slate-500">{{ $f['fecha'] }}</td><td class="py-2">Total {{ $mxn($f['total']) }} · pagado {{ $mxn($f['pagado']) }}</td><td class="py-2 text-right font-medium">{{ $mxn($f['pendiente']) }}</td>
                                        @break
                                @endswitch
                            </tr>
                        @empty
                            <x-panel.vacio colspan="4" mensaje="Nada por aquí todavía" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
