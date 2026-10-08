{{-- Correo del comprobante de venta directa (E7-02 / TG-115). El comprobante completo va en el PDF adjunto. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante de venta {{ $c['folio'] }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $c['cliente'] ? ' ' . $c['cliente']['nombre'] : '' }}:</p>

    <p>
        Te enviamos el comprobante de tu compra en
        <strong>{{ $c['distribuidora']['nombre_comercial'] }}</strong>.
    </p>

    <table style="border-collapse: collapse; margin: 12px 0;">
        <tr>
            <td style="padding: 2px 12px 2px 0; color: #6b7280;">Folio</td>
            <td style="padding: 2px 0;"><strong>{{ $c['folio'] }}</strong></td>
        </tr>
        <tr>
            <td style="padding: 2px 12px 2px 0; color: #6b7280;">Fecha</td>
            <td style="padding: 2px 0;">{{ $c['fecha']->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td style="padding: 2px 12px 2px 0; color: #6b7280;">Total</td>
            <td style="padding: 2px 0;"><strong>${{ number_format($c['total'], 2) }}</strong></td>
        </tr>
    </table>

    @if ($c['anulada'])
        <p style="color: #b91c1c;"><strong>Esta venta está ANULADA.</strong></p>
    @endif

    <p>El detalle completo va en el archivo PDF adjunto.</p>

    <p style="color: #6b7280; font-size: 12px;">Este documento no es un comprobante fiscal (CFDI).</p>
</body>
</html>
