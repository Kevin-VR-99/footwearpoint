<?php

namespace App\Support;

/**
 * TG-395 (A35) — Importes con la moneda escrita.
 *
 * El signo $ solo no dice de qué país es el dinero: lo usan el peso, el dólar
 * y media docena más. En las pantallas donde se deciden precios y se leen
 * ingresos, eso importa, y la tarjeta de métricas lo pide explícitamente.
 *
 * ESTÁ EN UN SOLO SITIO A PROPÓSITO. La pantalla de Métricas enseña el mismo
 * importe en la tarjeta del indicador y en la gráfica; si cada una lo armara
 * por su cuenta, una podría perder el «MXN» y nadie se daría cuenta, porque la
 * otra lo seguiría enseñando.
 *
 * No convierte nada ni redondea a su gusto: solo escribe. Para aritmética con
 * importes está Centavos, que no usa floats.
 */
final class Dinero
{
    public const MONEDA = 'MXN';

    public static function mxn(float|int|string $monto): string
    {
        return '$'.number_format((float) $monto, 2).' '.self::MONEDA;
    }
}
