<?php

namespace App\Support;

/**
 * Cómo se lee cada estado y de qué color se pinta.
 *
 * Antes esto vivía solo dentro de resources/views/components/ui/insignia-estado.blade.php,
 * así que desde un servicio no se podía usar: los avisos que le llegaban al
 * personal decían «Pedido PED-015 → listo_entrega» y «cambió de «colocado» a
 * «listo_entrega»», con guion bajo y todo, mientras que en la app del cliente
 * los mismos estados sí salían traducidos (TG-281).
 *
 * Ahora el mapa vive aquí y lo usan los dos. Si se agrega un estado, se
 * agrega en un solo lugar.
 */
final class Estados
{
    /** estado => [variante de color, texto que se lee]. */
    public const MAPA = [
        // Pedidos / genéricos
        'borrador'                 => ['neutral', 'Borrador'],
        'descartado'               => ['neutral', 'Descartado'],
        'colocado'                 => ['info', 'Colocado'],
        'en_revision'              => ['info', 'En revisión'],
        'confirmado'               => ['info', 'Confirmado'],
        'incluido_en_ciclo'        => ['info', 'En ciclo'],
        'solicitado_fabrica'       => ['info', 'Solicitado fábrica'],
        'en_transito'              => ['info', 'En tránsito'],
        'recibido_distribuidora'   => ['info', 'Recibido'],
        'parcialmente_disponible'  => ['warning', 'Parc. disponible'],
        'listo_entrega'            => ['warning', 'Listo entrega'],
        'vencido_recoleccion'      => ['danger', 'Recolección vencida'],
        'no_surtido'               => ['warning', 'No surtido'],
        'entregado'                => ['success', 'Entregado'],
        'rechazado'                => ['danger', 'Rechazado'],
        'cancelado'                => ['danger', 'Cancelado'],
        // Campañas (B)
        'en_importacion'           => ['info', 'En importación'],
        'activa'                   => ['success', 'Activa'],
        'cerrada'                  => ['neutral', 'Cerrada'],
        'pausada'                  => ['warning', 'Pausada'],
    ];

    /**
     * El texto que se lee. Si llegara un estado que no está en el mapa, se
     * devuelve con los guiones bajos cambiados por espacios: feo, pero
     * legible, y mejor que dejar la pantalla en blanco.
     */
    public static function etiqueta(?string $estado): string
    {
        if ($estado === null || $estado === '') {
            return '—';
        }

        return self::MAPA[$estado][1] ?? str_replace('_', ' ', $estado);
    }

    /** El color de la insignia. */
    public static function variante(?string $estado, string $porOmision = 'neutral'): string
    {
        if ($estado === null || $estado === '') {
            return $porOmision;
        }

        return self::MAPA[$estado][0] ?? $porOmision;
    }
}
