{{-- Aviso de cobro de la mensualidad o de una línea extra. Sin datos de tarjeta. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' '.$nombreDestinatario : '' }}:</p>

    @if ($tipo === 'linea_extra')
        <p>
            Recibimos <strong>${{ $monto }}</strong> (folio {{ $folio }}) por una línea extra
            de <strong>{{ $nombreDistribuidora }}</strong>.
        </p>
        <p>
            Ese cobro es solo de esta línea. Tu periodo ya pagado no cambia.
            @if ($pagadoHasta)
                Sigue hasta el {{ $pagadoHasta }}.
            @endif
        </p>
        <p>
            En la siguiente mensualidad se suma esta línea mientras siga activa por encima de las que incluye tu plan.
            Si cambias de plan, el precio y el conteo de líneas extra se recalculan.
        </p>
        <p>Líneas extra contratadas ahora: {{ $lineasExtra }}.</p>
    @else
        <p>
            Recibimos el pago de <strong>${{ $monto }}</strong> (folio {{ $folio }})
            de la mensualidad de <strong>{{ $nombreDistribuidora }}</strong>.
            @if ($plan)
                Plan: {{ $plan }}.
            @endif
        </p>
        @if ($pagadoHasta)
            <p>Tu suscripción queda pagada hasta el <strong>{{ $pagadoHasta }}</strong>.</p>
        @endif
        <p>
            @if ($lineasExtra > 0)
                La siguiente factura cobra el plan con el que iniciaste más {{ $lineasExtra }} {{ $lineasExtra === 1 ? 'línea extra activa' : 'líneas extra activas' }} por encima de las incluidas.
            @else
                La siguiente factura cobra el plan con el que iniciaste. Las líneas extra se suman solo mientras estén activas por encima de las incluidas.
            @endif
            Si cambias de plan, el precio final y el conteo de líneas extra se recalculan.
        </p>
    @endif

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint. No guardamos los datos de tu tarjeta.</p>
</body>
</html>
