<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * TG-219 (E15-04) — Los terminos y condiciones, publicos.
 *
 * Igual que el aviso: la pagina de inicio enlaza aqui y se tiene que poder
 * leer sin cuenta. El texto sale del mismo parcial que usa la pantalla de
 * aceptar documentos.
 */
new #[Layout('layouts.public')] #[Title('Términos y condiciones — FootwearPoint')] class extends Component
{
};
?>

<article class="fp-tarjeta p-5 sm:p-8 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-fp-sidebar">Términos y condiciones</h1>
    {{-- TG-321: versión y fecha a la vista desde arriba, en un tono que pasa AA. --}}
    <p class="mt-2 inline-flex flex-wrap gap-x-2 rounded-lg bg-fp-badge-info-bg px-3 py-1.5 text-sm text-fp-badge-info-fg">
        <span>Versión {{ config('legal.version') }}</span>
        <span aria-hidden="true" class="hidden sm:inline">·</span>
        <span>Última actualización: {{ config('legal.fecha_actualizacion') }}</span>
    </p>

    <div class="mt-6 text-[0.9375rem] sm:text-base">
        <x-legales.terminos />
    </div>

    <div class="mt-8 pt-6 border-t border-slate-200 flex flex-wrap gap-4 text-sm">
        <a href="{{ route('aviso-privacidad') }}" class="fp-enlace py-1">Ver el aviso de privacidad</a>
        <a href="{{ route('inicio') }}" class="fp-enlace py-1">Volver al inicio</a>
    </div>
</article>
