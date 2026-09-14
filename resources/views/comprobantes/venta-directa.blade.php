{{--
    Comprobante de venta directa (E7-02 / TG-115).

    Una sola vista para las tres salidas, para que digan lo mismo:
      - $modo = 'pantalla': con barra de botones (Imprimir, PDF, correo).
      - $modo = 'pdf': sin barra (la usa dompdf, también para el adjunto del correo).
    Al imprimir desde el navegador, la barra se oculta con @media print.

    Estilos simples y con tablas a propósito: dompdf no entiende flexbox,
    grid ni variables CSS.
--}}
@php
    $dinero = fn (float $monto) => '$' . number_format($monto, 2);
    $esPantalla = ($modo ?? 'pantalla') === 'pantalla';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ $c['folio'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            /* Helvetica ya viene en todo lector de PDF: dompdf no la incrusta y el
               archivo queda ligero. Muestra bien acentos y la ñ. */
            font-family: Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #1f2937;
            background: {{ $esPantalla ? '#f1f5f9' : '#ffffff' }};
        }
        .barra {
            max-width: 620px;
            margin: 24px auto 0;
            padding: 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }
        .barra a, .barra button {
            display: inline-block;
            margin: 0 6px 6px 0;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #6d4c41;
            background: #ffffff;
            color: #6d4c41;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }
        .barra .principal { background: #6d4c41; color: #ffffff; }
        .barra .volver { border: none; padding-left: 0; }
        .barra input[type=email] {
            width: 260px;
            max-width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
        }
        .aviso-ok { margin: 8px 0; padding: 8px 12px; border-radius: 8px; background: #dcfce7; color: #166534; }
        .aviso-error { margin: 6px 0 0; color: #b91c1c; font-size: 12px; }
        .etiqueta { display: block; margin: 10px 0 4px; color: #475569; font-size: 12px; }

        .ticket {
            max-width: 620px;
            margin: {{ $esPantalla ? '16px auto 32px' : '0 auto' }};
            padding: 28px;
            background: #ffffff;
            border: {{ $esPantalla ? '1px solid #e2e8f0' : 'none' }};
            border-radius: {{ $esPantalla ? '12px' : '0' }};
        }
        .centro { text-align: center; }
        .negocio { font-size: 18px; font-weight: bold; margin: 0; }
        .gris { color: #64748b; }
        .titulo {
            margin: 18px 0 12px;
            padding: 6px 0;
            border-top: 1px dashed #94a3b8;
            border-bottom: 1px dashed #94a3b8;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .anulada {
            margin: 0 0 12px;
            padding: 10px;
            border: 2px solid #b91c1c;
            color: #b91c1c;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
        }
        table { width: 100%; border-collapse: collapse; }
        .datos td { padding: 2px 0; vertical-align: top; }
        .datos td.clave { width: 110px; color: #64748b; }
        .lineas { margin-top: 14px; }
        .lineas th {
            padding: 6px 4px;
            border-bottom: 1px solid #1f2937;
            font-size: 11px;
            text-align: left;
        }
        .lineas td { padding: 6px 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .num, .lineas th.num { text-align: right; white-space: nowrap; }
        .totales { margin-top: 10px; }
        .totales td { padding: 3px 4px; }
        .totales .total td { font-size: 15px; font-weight: bold; border-top: 1px solid #1f2937; padding-top: 6px; }
        .pie { margin-top: 18px; padding-top: 10px; border-top: 1px dashed #94a3b8; font-size: 11px; }

        @media print {
            body { background: #ffffff; }
            .no-imprimir { display: none !important; }
            .ticket { margin: 0 auto; border: none; border-radius: 0; }
        }
    </style>
</head>
<body>

@if ($esPantalla)
    <div class="barra no-imprimir">
        <a class="volver" href="{{ route('punto-venta.index') }}">&larr; Punto de venta</a>
        <a class="volver" href="{{ route('reportes.index') }}">Reportes</a>
        <br>

        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        <a href="{{ route('ventas-directas.comprobante.pdf', $c['venta_id']) }}">Descargar PDF</a>

        @if (session('status'))
            <div class="aviso-ok">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('ventas-directas.comprobante.enviar', $c['venta_id']) }}">
            @csrf
            <label class="etiqueta" for="email">Enviar por correo (va el PDF adjunto)</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email', $c['cliente']['email'] ?? '') }}"
                placeholder="correo@cliente.com"
                required
            >
            <button type="submit">Enviar por correo</button>
            @error('email')
                <div class="aviso-error">{{ $message }}</div>
            @enderror
        </form>
    </div>
@endif

<div class="ticket">
    @if ($c['anulada'])
        <div class="anulada">VENTA ANULADA &mdash; este comprobante no es válido como compra</div>
    @endif

    <div class="centro">
        <p class="negocio">{{ $c['distribuidora']['nombre_comercial'] }}</p>
        @if ($c['distribuidora']['razon_social'])
            <div>{{ $c['distribuidora']['razon_social'] }}</div>
        @endif
        @if ($c['distribuidora']['rfc'])
            <div>RFC: {{ $c['distribuidora']['rfc'] }}</div>
        @endif
        @if ($c['sucursal']['nombre'])
            <div class="gris">{{ $c['sucursal']['nombre'] }}</div>
        @endif
        @if ($c['sucursal']['direccion'])
            <div class="gris">{{ $c['sucursal']['direccion'] }}</div>
        @endif
        @if ($c['sucursal']['telefono'])
            <div class="gris">Tel. {{ $c['sucursal']['telefono'] }}</div>
        @endif
    </div>

    <div class="titulo centro">COMPROBANTE DE VENTA</div>

    <table class="datos">
        <tr><td class="clave">Folio</td><td><strong>{{ $c['folio'] }}</strong></td></tr>
        <tr><td class="clave">Fecha</td><td>{{ $c['fecha']->format('d/m/Y H:i') }}</td></tr>
        @if ($c['cliente'])
            <tr><td class="clave">Cliente</td><td>{{ $c['cliente']['nombre'] }}</td></tr>
        @endif
        @if ($c['atendio'])
            <tr><td class="clave">Atendió</td><td>{{ $c['atendio'] }}</td></tr>
        @endif
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Variante</th>
                <th class="num">Cant.</th>
                <th class="num">P. unitario</th>
                <th class="num">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($c['lineas'] as $linea)
                <tr>
                    <td>
                        {{ $linea['producto'] }}
                        <div class="gris">Modelo {{ $linea['modelo'] }}</div>
                    </td>
                    <td>Talla {{ $linea['talla'] }}<br>{{ $linea['color'] }}</td>
                    <td class="num">{{ $linea['cantidad'] }}</td>
                    <td class="num">{{ $dinero($linea['precio_unitario']) }}</td>
                    <td class="num">{{ $dinero($linea['subtotal']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td class="num">Subtotal</td><td class="num" style="width: 120px;">{{ $dinero($c['subtotal']) }}</td></tr>
        @if ($c['descuento'] > 0)
            <tr><td class="num">Descuento</td><td class="num">-{{ $dinero($c['descuento']) }}</td></tr>
        @endif
        <tr class="total"><td class="num">Total</td><td class="num">{{ $dinero($c['total']) }}</td></tr>
        <tr><td class="num gris" colspan="2">
            Precios con IVA incluido. Base gravable {{ $dinero($c['base_gravable']) }} &middot; IVA (16%) {{ $dinero($c['iva']) }}
        </td></tr>
    </table>

    @if ($c['pago'])
        <table class="datos" style="margin-top: 12px;">
            <tr><td class="clave">Pago</td><td>{{ ucfirst($c['pago']['metodo']) }} &middot; {{ $dinero($c['pago']['monto']) }}</td></tr>
            <tr><td class="clave">Folio de pago</td><td>{{ $c['pago']['folio'] }}</td></tr>
        </table>
    @endif

    <div class="pie centro gris">
        Este documento no es un comprobante fiscal (CFDI).
    </div>
</div>

</body>
</html>
