{{-- Aviso de un paso de la solicitud de cambio. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombre ? ' '.$nombre : '' }}:</p>
    <p>{{ $mensaje }}</p>
    <p>Puedes ver el detalle y la línea de tiempo en la app, en Cambios.</p>
    <p style="color: #6b7280;">Recuerda: los cambios se resuelven con un vale; no hay devoluciones en efectivo.</p>
</body>
</html>
