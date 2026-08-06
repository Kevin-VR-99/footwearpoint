@props(['texto', 'variante' => 'neutral'])

@php
    $clases = match ($variante) {
        'info' => 'bg-fp-badge-info-bg text-fp-badge-info-fg',
        'success' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
        'warning' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        'danger' => 'bg-fp-badge-danger-bg text-fp-badge-danger-fg',
        default => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium $clases"]) }}>
    {{ $texto }}
</span>