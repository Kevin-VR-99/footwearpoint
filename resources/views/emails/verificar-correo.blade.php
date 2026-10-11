{{-- Confirmar el correo de la cuenta (TG-189 / A11). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' ' . $nombre : '' }}:</p>

    <p>Tu distribuidora te dio una cuenta en FootwearPoint. Confirma que este correo es tuyo
        para poder hacer pedidos desde la app:</p>

    <p>
        <a href="{{ $url }}"
           style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none;
                  padding: 12px 20px; border-radius: 8px; font-weight: bold;">Confirmar mi correo</a>
    </p>

    <p>El enlace vence en {{ $minutos }} minutos. Si ya venció, pide uno nuevo desde la app o
        dile a tu distribuidora que te lo reenvíe.</p>

    <p>Si no esperabas este correo, ignóralo: sin confirmarlo no pasa nada.</p>

    <p style="color: #6b7280; font-size: 12px;">Si el botón no funciona, copia y pega esta dirección en tu
        navegador:<br>{{ $url }}</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
