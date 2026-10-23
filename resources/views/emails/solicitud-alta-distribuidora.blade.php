{{-- Aviso al buzón de FootwearPoint de una solicitud de alta nueva (TG-220 / E2-08). Sin detalles técnicos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' ' . $nombreDestinatario : '' }}:</p>

    <p>Llegó una solicitud de alta en FootwearPoint.</p>

    <p>
        <strong>{{ $nombreDistribuidora }}</strong><br>
        Administrador: {{ $nombreAdministrador }} ({{ $emailAdministrador }})
        @if ($planSolicitado)
            <br>Plan que pidió: {{ $planSolicitado }}
        @endif
    </p>

    <p>Queda pendiente hasta que la revises. Mientras tanto, su administrador no puede entrar al panel.</p>

    <p><a href="{{ $urlPanel }}">Revisarla en el panel</a></p>

    <p style="color:#6b7280; font-size:12px;">FootwearPoint</p>
</body>
</html>
