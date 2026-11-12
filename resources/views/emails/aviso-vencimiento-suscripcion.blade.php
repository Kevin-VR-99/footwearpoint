{{-- Aviso de vencimiento de la mensualidad. El texto lo arma el comando. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' '.$nombreDestinatario : '' }}:</p>
    <p>{{ $texto }}</p>
    <p>Puedes pagarla desde el panel, en Configuración, pestaña Suscripción.</p>
    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
