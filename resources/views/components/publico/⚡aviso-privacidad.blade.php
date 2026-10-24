<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * TG-219 (E15-04) — El aviso de privacidad, publico.
 *
 * Publica a proposito: la pagina de inicio enlaza aqui y quien todavia no
 * tiene cuenta tiene que poder leerlo. El texto sale del parcial que tambien
 * usa la pantalla de aceptar documentos, para que no haya dos versiones.
 */
new #[Layout('layouts.public')] #[Title('Aviso de privacidad — FootwearPoint')] class extends Component
{
};
?>

<article class="fp-tarjeta p-6 sm:p-8 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-slate-900">Aviso de privacidad</h1>
    <p class="text-sm text-slate-500 mt-1">Versión {{ config('legal.version') }} · Última actualización: {{ config('legal.fecha_actualizacion') }}</p>

    <div class="mt-6 text-sm text-slate-600 space-y-2">
        <x-legales.aviso-privacidad />
    </div>

    <div class="mt-8 pt-6 border-t border-slate-200 flex flex-wrap gap-4 text-sm">
        <a href="{{ route('terminos') }}" class="text-[#2563EB] hover:underline">Ver los términos y condiciones</a>
        <a href="{{ route('inicio') }}" class="text-[#2563EB] hover:underline">Volver al inicio</a>
    </div>
</article>
