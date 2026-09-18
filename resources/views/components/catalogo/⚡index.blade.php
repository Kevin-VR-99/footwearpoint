<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public string $pestanaActiva = 'productos';
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Catálogo</h1>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm" style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    <div class="border-b border-slate-200 mb-6 flex gap-6 flex-wrap">
        <button type="button" wire:click="$set('pestanaActiva', 'productos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'productos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Productos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'lineas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'lineas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Líneas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'marcas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'marcas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Marcas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'campanas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'campanas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Temporadas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'categorias')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'categorias' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
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
