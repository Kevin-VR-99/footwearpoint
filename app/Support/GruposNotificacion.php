<?php

namespace App\Support;

/**
 * TG-196: mapa de `tipo` de notificación → grupo de la campana (estilo
 * Mercado Libre). Sin migraciones: el grupo se calcula al vuelo.
 *
 * Para agregar un tipo nuevo basta con sumarlo a TIPOS; lo que no esté
 * aquí cae por prefijo y, si tampoco, en "generales".
 */
final class GruposNotificacion
{
    public const PEDIDOS = 'pedidos';
    public const PAGOS = 'pagos';
    public const PROMOCIONES = 'promociones';
    public const GENERALES = 'generales';

    /** Orden en que se muestran, con título e icono (nombre de icono del panel). */
    public const GRUPOS = [
        self::PEDIDOS     => ['titulo' => 'Pedidos y entregas', 'icono' => 'caja'],
        self::PAGOS       => ['titulo' => 'Pagos y vales', 'icono' => 'billete'],
        self::PROMOCIONES => ['titulo' => 'Promociones', 'icono' => 'etiqueta'],
        self::GENERALES   => ['titulo' => 'Generales y sistema', 'icono' => 'campana'],
    ];

    /** Tipos que hoy crea el código (app/Services/Notificacion). */
    public const TIPOS = [
        'pedido_estado'             => self::PEDIDOS,
        'pedido_llegada'            => self::PEDIDOS,
        'pedido_anticipo_pendiente' => self::PEDIDOS,
        'pago_mercado_pago'         => self::PAGOS,
        'pago_suscripcion'          => self::PAGOS,
        'vale_por_vencer'           => self::PAGOS,
        'catalogo_ia_listo'         => self::GENERALES,
        'catalogo_ia_error'         => self::GENERALES,
    ];

    /** Respaldo por prefijo para tipos futuros. */
    public const PREFIJOS = [
        'pedido_'  => self::PEDIDOS,
        'entrega_' => self::PEDIDOS,
        'pago_'    => self::PAGOS,
        'vale_'    => self::PAGOS,
        'promo'    => self::PROMOCIONES,
        'campana'  => self::PROMOCIONES,
    ];

    public static function de(?string $tipo): string
    {
        $tipo = (string) $tipo;

        if (isset(self::TIPOS[$tipo])) {
            return self::TIPOS[$tipo];
        }

        foreach (self::PREFIJOS as $prefijo => $grupo) {
            if (str_starts_with($tipo, $prefijo)) {
                return $grupo;
            }
        }

        return self::GENERALES;
    }

    public static function existe(string $grupo): bool
    {
        return isset(self::GRUPOS[$grupo]);
    }

    /** @return list<string> tipos conocidos de un grupo (para filtrar en BD). */
    public static function tiposDe(string $grupo): array
    {
        return array_keys(array_filter(self::TIPOS, fn ($g) => $g === $grupo));
    }
}
