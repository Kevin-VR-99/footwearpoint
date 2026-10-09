@props([
    'encabezadoSticky' => false,
])

{{-- TG-179: tabla estándar del panel dentro de una tarjeta. --}}
<div {{ $attributes->class(['overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            @isset($encabezado)
                <thead @class([
                    'bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted',
                    'sticky top-0 z-10' => $encabezadoSticky,
                ])>
                    {{ $encabezado }}
                </thead>
            @endisset
            <tbody class="divide-y divide-slate-100">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
