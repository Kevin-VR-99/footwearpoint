{{-- Acuse al administrador de la distribuidora de que llegó su solicitud de alta (TG-220 / E2-08). Sin detalles técnicos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' ' . $nombreDestinatario : '' }}:</p>

    <p>Recibimos la solicitud de alta de <strong>{{ $nombreDistribuidora }}</strong> en FootwearPoint.</p>

    <p>La revisaremos pronto. Te avisaremos por correo cuando sea aprobada o rechazada.</p>

    <p>Mientras tanto, todavía no podrás entrar al panel.</p>

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
