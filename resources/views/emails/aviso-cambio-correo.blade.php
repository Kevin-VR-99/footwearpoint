{{-- Aviso al correo anterior: alguien pidió cambiar el correo de la cuenta. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' ' . $nombre : '' }}:</p>

    <p>Desde la app se pidió cambiar el correo de tu cuenta de FootwearPoint a <strong>{{ $nuevo }}</strong>.
        El cambio solo se aplica cuando se confirma desde ese correo nuevo.</p>

    <p>Si no fuiste tú, no abras ningún enlace y cambia tu contraseña desde la app, o avísale a tu distribuidora.</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
