{{-- Aviso de cambio de estado de una distribuidora (TG-196 / G5). Sin detalles técnicos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' ' . $nombreDestinatario : '' }}:</p>

    @switch($evento)
        @case('aprobada')
            @php($mxn = fn ($n) => '$' . number_format((float) $n, 2) . ' MXN')
            <p>¡Buenas noticias! Tu distribuidora <strong>{{ $nombreDistribuidora }}</strong> fue aprobada en FootwearPoint.</p>
            <p>Para empezar a usar FootwearPoint, entra al panel con tu correo y contraseña: lo primero que verás es
                el pago de tu primer mes con Mercado Pago. En cuanto se confirme el pago, tendrás acceso a todo el panel.
                Tu mes empieza a contar el día en que pagues.</p>

            @if (! empty($planes))
                <p style="margin-bottom: 6px;"><strong>Precios de los planes</strong></p>
                <table cellpadding="0" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 560px; font-size: 13px;">
                    <tr style="background-color: #f3f4f6;">
                        <th align="left" style="padding: 6px 8px; border: 1px solid #e5e7eb;">Plan</th>
                        <th align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">Precio mensual</th>
                        <th align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">Líneas incluidas</th>
                        <th align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">Línea extra al mes</th>
                    </tr>
                    @foreach ($planes as $plan)
                        <tr style="{{ $plan['destacado'] ? 'background-color: #ecfdf5; font-weight: bold;' : '' }}">
                            <td style="padding: 6px 8px; border: 1px solid #e5e7eb;">{{ $plan['nombre'] }}@if ($plan['destacado']) (tu plan)@endif</td>
                            <td align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">{{ $mxn($plan['precio_base_mensual']) }}</td>
                            <td align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">{{ $plan['lineas_incluidas'] }}</td>
                            <td align="right" style="padding: 6px 8px; border: 1px solid #e5e7eb;">{{ $mxn($plan['precio_linea_extra']) }}</td>
                        </tr>
                    @endforeach
                </table>
                <p style="color: #4b5563;">Si tienes más líneas activas de las que incluye tu plan, cada línea extra se cobra aparte al precio indicado.
                    Después de tu primer pago podrás ver tu suscripción y sus opciones en Configuración &gt; Suscripción.</p>
            @endif

            <p><a href="{{ $urlLogin }}">Entrar a FootwearPoint y pagar mi primer mes</a></p>
            @break

        @case('rechazada')
            <p>La solicitud de <strong>{{ $nombreDistribuidora }}</strong> para usar FootwearPoint fue rechazada.</p>
            @if ($motivo)
                <p><strong>Motivo:</strong> {{ $motivo }}</p>
            @endif
            <p>Si tienes dudas, comunícate con FootwearPoint.</p>
            @break

        @case('suspendida')
            <p><strong>{{ $nombreDistribuidora }}</strong> fue suspendida en FootwearPoint.</p>
            <p>Mientras siga suspendida, ni tu personal ni tus clientes (mayoristas y directos) podrán usar el panel ni la app.
                Tu información se conserva.</p>
            <p>Comunícate con FootwearPoint para reactivarla.</p>
            @break

        @case('reactivada')
            <p><strong>{{ $nombreDistribuidora }}</strong> fue reactivada en FootwearPoint.</p>
            <p>Tu personal y tus clientes (mayoristas y directos) ya pueden volver a usar el panel y la app.</p>
            <p><a href="{{ $urlLogin }}">Entrar a FootwearPoint</a></p>
            @break

        @default
            <p>Cambió el estado de <strong>{{ $nombreDistribuidora }}</strong> en FootwearPoint.</p>
    @endswitch

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
