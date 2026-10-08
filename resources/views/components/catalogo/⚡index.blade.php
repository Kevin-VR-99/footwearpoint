<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public string $pestanaActiva = 'productos';
};
?>

<div class="space-y-6">
    {{-- Cabecera --}}
    <section class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <div class="absolute inset-y-0 left-0 w-1.5 bg-fp-primary" aria-hidden="true"></div>
        <div class="absolute -right-16 -top-16 h-40 w-40 rounded-full bg-fp-primary/10 blur-2xl" aria-hidden="true"></div>
        <div class="absolute -bottom-20 right-10 h-36 w-36 rounded-full bg-fp-primary/5 blur-2xl" aria-hidden="true"></div>

        <div class="relative px-6 py-6 sm:px-8 sm:py-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Catálogo</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-fp-sidebar sm:text-3xl">Gestión de catálogo</h1>
            <p class="mt-1.5 max-w-2xl text-sm text-fp-text-muted">
                Productos, líneas, marcas, temporadas y categorías de tu distribuidora.
            </p>
        </div>
    </section>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="rounded-xl border border-fp-badge-success-fg/15 bg-fp-badge-success-bg px-4 py-2.5 text-sm text-fp-badge-success-fg shadow-sm"
        style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    <div class="flex flex-wrap gap-1.5 rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-sm">
        <button type="button" wire:click="$set('pestanaActiva', 'productos')"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'productos' ? 'bg-fp-primary text-white shadow-sm' : 'text-fp-text-muted hover:bg-fp-page hover:text-slate-900' }}">
            Productos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'lineas')"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'lineas' ? 'bg-fp-primary text-white shadow-sm' : 'text-fp-text-muted hover:bg-fp-page hover:text-slate-900' }}">
            Líneas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'marcas')"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'marcas' ? 'bg-fp-primary text-white shadow-sm' : 'text-fp-text-muted hover:bg-fp-page hover:text-slate-900' }}">
            Marcas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'campanas')"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'campanas' ? 'bg-fp-primary text-white shadow-sm' : 'text-fp-text-muted hover:bg-fp-page hover:text-slate-900' }}">
            Temporadas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'categorias')"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors {{ $pestanaActiva === 'categorias' ? 'bg-fp-primary text-white shadow-sm' : 'text-fp-text-muted hover:bg-fp-page hover:text-slate-900' }}">
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
