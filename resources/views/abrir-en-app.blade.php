<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abrir en FootwearPoint</title>
</head>
<body>
    <main>
        <h1>{{ $tipo === 'pedido' ? 'Abrir el pedido' : 'Abrir el producto' }}</h1>
        <p>Este enlace abre la app de FootwearPoint.</p>
        <p>
            <a id="abrir-app" href="intent://footwearpoint.app/abrir/{{ $tipo }}/{{ $id }}#Intent;scheme=https;package=com.footwearpoint.app;S.browser_fallback_url={{ rawurlencode('https://footwearpoint.app/') }};end">Abrir en la app</a>
        </p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
    </main>
</body>
</html>
