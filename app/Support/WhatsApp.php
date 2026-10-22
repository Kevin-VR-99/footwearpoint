<?php

namespace App\Support;

use App\Models\Distribuidora;
use App\Models\Pedido;

/**
 * Enlaces de WhatsApp (wa.me) con el texto ya escrito.
 *
 * No se usa ninguna API de WhatsApp ni se guarda nada: el enlace abre
 * WhatsApp (app o web) con el mensaje listo, y quien comparte elige a quién
 * mandarlo o, si se conoce el teléfono, ya va dirigido a esa persona.
 */
class WhatsApp
{
    /** Lada de México: los teléfonos de 10 dígitos se completan con 52. */
    public const LADA_PAIS = '52';

    public static function enlace(string $texto, ?string $telefono = null): string
    {
        $numero = self::normalizarTelefono($telefono);

        return 'https://wa.me/'.($numero ?? '').'?text='.rawurlencode(trim($texto));
    }

    /** Solo dígitos; 10 dígitos se toman como número de México. Null si no sirve. */
    public static function normalizarTelefono(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefono) ?? '';

        if (strlen($digitos) === 10) {
            return self::LADA_PAIS.$digitos;
        }

        return strlen($digitos) >= 11 && strlen($digitos) <= 15 ? $digitos : null;
    }

    public static function textoTienda(Distribuidora $distribuidora, ?string $url): string
    {
        return trim(sprintf(
            "¡Hola! Te comparto el catálogo de %s 👟\n%s",
            $distribuidora->nombre_comercial,
            (string) $url,
        ));
    }

    /** @param array{nombre:string, precio_texto?:string, modelo?:string} $producto */
    public static function textoProducto(array $producto, Distribuidora $distribuidora, ?string $url): string
    {
        $lineas = [
            '¡Mira este modelo de '.$distribuidora->nombre_comercial.'! 👟',
            $producto['nombre'].(! empty($producto['modelo']) ? ' (modelo '.$producto['modelo'].')' : ''),
        ];

        if (! empty($producto['precio_texto'])) {
            $lineas[] = 'Precio: '.$producto['precio_texto'];
        }

        if ($url) {
            $lineas[] = $url;
        }

        return implode("\n", $lineas);
    }

    /**
     * Resumen del pedido para mandárselo al cliente: folio, estado, piezas,
     * total y lo que falta por pagar.
     */
    public static function textoPedido(Pedido $pedido, float $saldo, ?string $urlTienda = null): string
    {
        $nombre = $pedido->clienteDirecto?->nombre ?? $pedido->revendedorAfiliacion?->revendedor?->nombre;

        $lineas = [
            'Hola'.($nombre ? ' '.$nombre : '').', te comparto tu pedido '.$pedido->folio.'.',
            'Estado: '.Estados::etiqueta((string) $pedido->estado),
        ];

        foreach ($pedido->detalle->take(10) as $linea) {
            $lineas[] = sprintf(
                '• %d × %s %s%s',
                (int) $linea->cantidad,
                $linea->producto_nombre,
                $linea->talla ? 'talla '.$linea->talla : '',
                $linea->color ? ' · '.$linea->color : '',
            );
        }

        if ($pedido->detalle->count() > 10) {
            $lineas[] = '… y '.($pedido->detalle->count() - 10).' productos más.';
        }

        $lineas[] = 'Total: $'.number_format((float) $pedido->total, 2);

        if ($saldo > 0) {
            $lineas[] = 'Por pagar: $'.number_format($saldo, 2);
        }

        if ($urlTienda) {
            $lineas[] = 'Catálogo: '.$urlTienda;
        }

        return implode("\n", $lineas);
    }

    public static function telefonoDelPedido(Pedido $pedido): ?string
    {
        return $pedido->clienteDirecto?->telefono
            ?? $pedido->revendedorAfiliacion?->revendedor?->telefono;
    }
}
