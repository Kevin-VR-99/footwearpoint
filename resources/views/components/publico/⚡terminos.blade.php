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

<article class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 sm:p-8 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-slate-900">Términos y condiciones</h1>
    <p class="text-sm text-slate-500 mt-1">Versión 1.0</p>

    <div class="mt-6 text-sm text-slate-600 space-y-2">
        <x-legales.terminos />
    </div>

    <div class="mt-8 pt-6 border-t border-slate-200 flex flex-wrap gap-4 text-sm">
        <a href="{{ route('aviso-privacidad') }}" class="text-[#2563EB] hover:underline">Ver el aviso de privacidad</a>
        <a href="{{ route('inicio') }}" class="text-[#2563EB] hover:underline">Volver al inicio</a>
    </div>
</article>
