@props([
    'titulo',
    'subtitulo' => null,
    'ancho' => 'max-w-lg',
    'cerrar' => null,
    'formulario' => null,
])

@php($idTitulo = 'modal-'.\Illuminate\Support\Str::slug($titulo))

{{-- Modal del panel: fondo que se desvanece y ventana que entra; Escape o clic afuera ejecutan "cerrar" (acción Livewire). --}}
<div class="fp-desvanecer fixed inset-0 z-50 flex items-center justify-center p-4 fp-modal-fondo"
    role="dialog" aria-modal="true" aria-labelledby="{{ $idTitulo }}"
    @if ($cerrar) wire:click.self="{{ $cerrar }}" x-data x-on:keydown.escape.window="$wire.{{ $cerrar }}()" @endif>
    <{{ $formulario ? 'form' : 'div' }} @if ($formulario) wire:submit="{{ $formulario }}" @endif
        {{ $attributes->class(['fp-modal w-full max-h-[90vh] overflow-y-auto p-6', $ancho]) }}>
        <div class="mb-4">
            <h3 id="{{ $idTitulo }}" class="text-lg font-semibold text-fp-sidebar">{{ $titulo }}</h3>
            @if ($subtitulo)
                <p class="mt-1 text-sm text-fp-text-muted">{{ $subtitulo }}</p>
            @endif
        </div>
        {{ $slot }}
        @isset($pie)
            <div class="mt-5 flex justify-end gap-2">{{ $pie }}</div>
        @endisset
    </{{ $formulario ? 'form' : 'div' }}>
</div>
