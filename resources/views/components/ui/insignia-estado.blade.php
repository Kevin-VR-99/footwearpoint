@props(['texto', 'variante' => 'gris'])

@php
    $clases = match ($variante) {
        'azul' => 'bg-fp-badge-azul-bg text-fp-badge-azul-text',
        'verde' => 'bg-fp-badge-verde-bg text-fp-badge-verde-text',
        'rojo' => 'bg-fp-badge-rojo-bg text-fp-badge-rojo-text',
        default => 'bg-fp-badge-gris-bg text-fp-badge-gris-text',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium $clases"]) }}>
    {{ $texto }}
</span>