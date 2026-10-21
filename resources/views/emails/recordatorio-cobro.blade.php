{{-- Recordatorio de cobro. El mismo contenido se muestra como vista previa en Reportes. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $d['distribuidora']['nombre'] }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    @include('emails.partials.recordatorio-cobro-cuerpo', ['d' => $d])
</body>
</html>
