<p>Hola {{ $d['nombre'] }}:</p>
<p>
    Te escribimos de <strong>{{ $d['distribuidora']['nombre'] }}</strong> para recordarte que tienes un saldo pendiente de
    <strong>${{ number_format($d['saldo'], 2) }}</strong>.
</p>
<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 560px; font-size: 13px;">
    <thead>
        <tr style="background: #f1f5f9; text-align: left;">
            <th style="border-bottom: 1px solid #e2e8f0;">Pedido</th>
            <th style="border-bottom: 1px solid #e2e8f0;">Fecha</th>
            <th style="border-bottom: 1px solid #e2e8f0; text-align: right;">Total</th>
            <th style="border-bottom: 1px solid #e2e8f0; text-align: right;">Pagado</th>
            <th style="border-bottom: 1px solid #e2e8f0; text-align: right;">Pendiente</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($d['pedidos'] as $p)
            <tr>
                <td style="border-bottom: 1px solid #f1f5f9;">{{ $p['folio'] }}</td>
                <td style="border-bottom: 1px solid #f1f5f9;">{{ $p['fecha'] ?? '—' }}</td>
                <td style="border-bottom: 1px solid #f1f5f9; text-align: right;">${{ number_format($p['total'], 2) }}</td>
                <td style="border-bottom: 1px solid #f1f5f9; text-align: right;">${{ number_format($p['pagado'], 2) }}</td>
                <td style="border-bottom: 1px solid #f1f5f9; text-align: right;"><strong>${{ number_format($p['pendiente'], 2) }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Si ya realizaste tu pago, por favor ignora este mensaje. Si tienes dudas, contáctanos:</p>
<p style="margin: 0;">
    <strong>{{ $d['distribuidora']['nombre'] }}</strong><br>
    @if (filled($d['distribuidora']['telefono'])) Teléfono: {{ $d['distribuidora']['telefono'] }}<br> @endif
    @if (filled($d['distribuidora']['email'])) Correo: {{ $d['distribuidora']['email'] }}<br> @endif
    @if (filled($d['distribuidora']['direccion'])) {{ $d['distribuidora']['direccion'] }} @endif
</p>
<p style="color: #64748b; font-size: 12px;">Gracias por tu preferencia.</p>
