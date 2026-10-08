<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| TG-226 (G7) — Página a la que regresa Mercado Pago después de pagar.
|
| Es neutral a propósito: el "status" de la URL solo cambia el texto. Lo que
| cuenta es la verificación que hace el servidor con Mercado Pago, así que
| aquí no se consulta ni se cambia nada.
*/
new #[Layout('layouts.guest')] #[Title('Pago con Mercado Pago — FootwearPoint')] class extends Component {
    public string $resultado = 'otro';

    public function mount(): void
    {
        $status = request()->query('status', request()->query('collection_status'));

        $this->resultado = match ($status) {
            'approved' => 'aprobado',
            'pending', 'in_process' => 'en_proceso',
            'rejected', 'cancelled', 'null' => 'no_completado',
            default => 'otro',
        };
    }
};
?>

<div class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
    <p class="text-xs font-semibold uppercase tracking-wider text-fp-primary">FootwearPoint</p>

    @if ($resultado === 'aprobado')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">¡Gracias por tu pago!</h1>
        <p class="mt-2 text-sm text-slate-600">Mercado Pago está confirmando tu pago con la distribuidora.</p>
    @elseif ($resultado === 'en_proceso')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Tu pago está en proceso</h1>
        <p class="mt-2 text-sm text-slate-600">Mercado Pago todavía no lo confirma.</p>
    @elseif ($resultado === 'no_completado')
        <h1 class="mt-2 text-xl font-semibold text-slate-900">El pago no se completó</h1>
        <p class="mt-2 text-sm text-slate-600">No se te cobró nada. Puedes intentarlo de nuevo desde la app.</p>
    @else
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Regresaste de Mercado Pago</h1>
    @endif

    <p class="mt-4 text-sm text-slate-600">
        Regresa a la app de FootwearPoint y abre tu pedido para ver el estado de tu anticipo.
    </p>
</div>
