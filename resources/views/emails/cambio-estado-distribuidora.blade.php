{{-- Aviso de cambio de estado de una distribuidora (TG-196 / G5). Sin detalles técnicos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FootwearPoint</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5;">
    <p>Hola{{ $nombreDestinatario ? ' ' . $nombreDestinatario : '' }}:</p>

    @switch($evento)
        @case('aprobada')
            <p>Tu distribuidora <strong>{{ $nombreDistribuidora }}</strong> fue aprobada en FootwearPoint.</p>
            <p>Ya puedes entrar al panel con tu correo y contraseña para preparar tu espacio.</p>
            <p><a href="{{ $urlLogin }}">Entrar a FootwearPoint</a></p>
            @break

        @case('rechazada')
            <p>La solicitud de <strong>{{ $nombreDistribuidora }}</strong> para usar FootwearPoint fue rechazada.</p>
            @if ($motivo)
                <p><strong>Motivo:</strong> {{ $motivo }}</p>
            @endif
            <p>Si tienes dudas, comunícate con FootwearPoint.</p>
            @break

        @case('suspendida')
            <p><strong>{{ $nombreDistribuidora }}</strong> fue suspendida en FootwearPoint.</p>
            <p>Mientras siga suspendida, ni tu personal ni tus revendedores y clientes podrán usar el panel ni la app.
                Tu información se conserva.</p>
            <p>Comunícate con FootwearPoint para reactivarla.</p>
            @break

        @case('reactivada')
            <p><strong>{{ $nombreDistribuidora }}</strong> fue reactivada en FootwearPoint.</p>
            <p>Tu personal, revendedores y clientes ya pueden volver a usar el panel y la app.</p>
            <p><a href="{{ $urlLogin }}">Entrar a FootwearPoint</a></p>
            @break

        @default
            <p>Cambió el estado de <strong>{{ $nombreDistribuidora }}</strong> en FootwearPoint.</p>
    @endswitch

    <p style="color: #6b7280; font-size: 12px;">Este es un aviso automático de FootwearPoint.</p>
</body>
</html>
