{{-- Confirmar un correo o teléfono nuevo pedido desde la app. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' ' . $nombre : '' }}:</p>

    @if ($tipo === 'email')
        <p>Pediste usar <strong>{{ $valor }}</strong> como tu correo de FootwearPoint. Confírmalo para que el cambio se aplique:</p>
    @else
        <p>Pediste cambiar tu teléfono de FootwearPoint a <strong>{{ $valor }}</strong>. Confírmalo para que el cambio se aplique:</p>
    @endif

    <p>
        <a href="{{ $url }}"
           style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none;
                  padding: 12px 20px; border-radius: 8px; font-weight: bold;">Confirmar el cambio</a>
    </p>

    <p>El enlace vence en {{ $minutos }} minutos. Si no lo pediste tú, ignora este correo: sin confirmarlo no cambia nada.</p>

    <p style="color: #6b7280; font-size: 12px;">Si el botón no funciona, copia y pega esta dirección en tu
        navegador:<br>{{ $url }}</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
