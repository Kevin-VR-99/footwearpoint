{{-- Enlace para restablecer la contraseña (TG-188 / A6). Sin datos técnicos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' ' . $nombre : '' }}:</p>

    <p>Pediste cambiar tu contraseña de FootwearPoint. Usa este botón para elegir una nueva:</p>

    <p>
        <a href="{{ $urlEnlace }}"
           style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none;
                  padding: 12px 20px; border-radius: 8px; font-weight: bold;">Cambiar mi contraseña</a>
    </p>

    <p>El enlace sirve una sola vez y vence en {{ $minutos }} minutos. Si ya venció, vuelve a pedir uno nuevo
        desde «¿Olvidaste tu contraseña?».</p>

    <p>Si no lo pediste, ignora este correo: tu contraseña no cambia mientras no uses el enlace.</p>

    <p style="color: #6b7280; font-size: 12px;">Si el botón no funciona, copia y pega esta dirección en tu
        navegador:<br>{{ $urlEnlace }}</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
