<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public string $pestanaActiva = 'productos';
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Catálogo</h1>
        <p class="mt-1 text-sm text-slate-500">Gestiona productos, líneas, marcas, temporadas y categorías.</p>
    </div>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-5 rounded-lg bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2.5 text-sm" style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    <div class="mb-8 flex flex-wrap gap-1.5 rounded-xl border border-slate-200/80 bg-white p-1.5 shadow-sm">
        <button type="button" wire:click="$set('pestanaActiva', 'productos')"
            class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'productos' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
            Productos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'lineas')"
            class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'lineas' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
            Líneas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'marcas')"
            class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'marcas' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
            Marcas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'campanas')"
            class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'campanas' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
            Temporadas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'categorias')"
            class="rounded-lg px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'categorias' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
            Categorías
        </button>
    </div>

    @if ($pestanaActiva === 'productos')
        <livewire:catalogo.productos :key="'catalogo-productos'" />
    @elseif ($pestanaActiva === 'lineas')
        <livewire:catalogo.lineas :key="'catalogo-lineas'" />
    @elseif ($pestanaActiva === 'marcas')
        <livewire:catalogo.marcas :key="'catalogo-marcas'" />
    @elseif ($pestanaActiva === 'campanas')
        <livewire:catalogo.campanas :key="'catalogo-campanas'" />
    @elseif ($pestanaActiva === 'categorias')
        <livewire:catalogo.categorias :key="'catalogo-categorias'" />
    @endif
</div>
