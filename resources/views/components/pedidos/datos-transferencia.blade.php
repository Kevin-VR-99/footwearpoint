@props(['pedido'])

@php
    $transferencia = app(\App\Services\Pedido\DatosTransferenciaPedido::class)->para($pedido);
    $comprobante = $pedido->comprobanteTransferencia;
@endphp

@if ($transferencia)
    <div class="mb-6 fp-tarjeta p-4 fp-aparecer" data-transferencia-spei>
        <h3 class="font-medium text-slate-800">Pagar por transferencia</h3>
        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Banco</dt><dd>{{ $transferencia['banco'] }}</dd></div>
            <div><dt class="text-slate-500">CLABE</dt><dd class="tabular-nums" data-clabe-pedido>{{ $transferencia['clabe'] }}</dd></div>
            <div><dt class="text-slate-500">Titular</dt><dd>{{ $transferencia['titular'] }}</dd></div>
            <div><dt class="text-slate-500">Monto</dt><dd class="tabular-nums">${{ number_format($transferencia['monto'], 2) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-slate-500">Concepto</dt><dd>{{ $transferencia['concepto'] }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-slate-600" data-aviso-spei>{{ $transferencia['aviso'] }}.</p>
        @if ($comprobante)
            <p class="mt-3 text-sm">
                Comprobante recibido: {{ $comprobante->nombre_original }}.
                El archivo no cuenta como pago. El pago cuenta cuando se confirma.
                <a href="{{ route('pedidos.comprobante-transferencia', $pedido->id) }}" class="fp-enlace">Ver archivo</a>
            </p>
        @else
            <p class="mt-3 text-sm text-slate-500">Todavía no hay comprobante. Si el cliente sube uno, el pago sigue pendiente hasta que se confirme.</p>
        @endif
    </div>
@endif
