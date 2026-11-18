{{-- TG-337: un administrador cambió el rol o la contraseña de la cuenta. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' ' . $nombre : '' }}:</p>

    <p>{{ $mensaje }}</p>

    <p>Si no lo reconoces, avísale a tu administrador.</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
