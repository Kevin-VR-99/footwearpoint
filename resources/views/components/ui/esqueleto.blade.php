{{-- Esqueleto de carga: filas grises con brillo mientras Livewire responde. --}}
@props(['filas' => 3])
<div {{ $attributes->class('space-y-2') }} role="status" aria-label="Cargando">
    @for ($i = 0; $i < $filas; $i++)
        <div class="fp-esqueleto h-4" style="width: {{ [100, 85, 70, 92, 60][$i % 5] }}%"></div>
    @endfor
    <span class="sr-only">Cargando…</span>
</div>
