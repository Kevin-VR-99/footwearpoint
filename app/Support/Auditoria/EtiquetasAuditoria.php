<?php

namespace App\Support\Auditoria;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TG-267 (U7): nombres en español de lo que guarda la auditoría.
 *
 * En la tabla la acción se queda con su nombre interno (por ejemplo
 * "stock.entrada"); aquí se traduce para mostrarla, y se agrupa por área
 * según la primera parte del nombre.
 */
final class EtiquetasAuditoria
{
    public const ETIQUETAS = [
        // Pedidos
        'pedido.cambio_estado'            => 'Cambio de estado de pedido',
        'pedido.pospuesto_por_anticipo'   => 'Pedido pospuesto por falta de anticipo',
        // Pagos
        'pago.mercado_pago.preferencia'   => 'Cobro con Mercado Pago creado',
        'pago.mercado_pago.aplicado'      => 'Pago con Mercado Pago aplicado',
        'pago.mercado_pago.reemplazado'   => 'Cobro con Mercado Pago reemplazado',
        'pago.mercado_pago.vencido'       => 'Cobro con Mercado Pago vencido',
        'mercado_pago.conectado'          => 'Mercado Pago conectado',
        'mercado_pago.desconectado'       => 'Mercado Pago desconectado',
        // Suscripción
        'suscripcion.renovada'            => 'Suscripción renovada',
        'suscripcion.extras_recalculadas' => 'Líneas extra de la suscripción recalculadas',
        // Configuración
        'linea_extra.activada'            => 'Línea activada',
        'linea_extra.desactivada'         => 'Línea desactivada',
        'distribuidora.subdominio_actualizado' => 'Subdominio de la tienda actualizado',
        // Seguridad
        'password.enlace_restablecimiento' => 'Enlace para restablecer contraseña enviado',
        'password.temporal_generada'      => 'Contraseña temporal generada',
        // Stock y mostrador
        'stock.entrada'                   => 'Entrada de stock',
        'stock.ajuste'                    => 'Ajuste de stock',
        'venta_mostrador.registrada'      => 'Venta de mostrador',
        // Precios de la distribuidora
        'oferta.precio_mayorista_actualizado' => 'Precio de mayoreo cambiado',
        'oferta.precio_mayorista_restablecido' => 'Precio de mayoreo restablecido',
        'oferta.producto_ocultado'        => 'Producto ocultado',
        'oferta.producto_mostrado'        => 'Producto vuelto a mostrar',
        // Catálogo
        'catalogo.linea.creada'           => 'Línea creada',
        'catalogo.linea.actualizada'      => 'Línea modificada',
        'catalogo.linea.eliminada'        => 'Línea eliminada',
        'catalogo.marca.creada'           => 'Marca creada',
        'catalogo.marca.actualizada'      => 'Marca modificada',
        'catalogo.marca.eliminada'        => 'Marca eliminada',
        'catalogo.temporada.creada'       => 'Temporada creada',
        'catalogo.temporada.actualizada'  => 'Temporada modificada',
        'catalogo.temporada.eliminada'    => 'Temporada eliminada',
        'catalogo.categoria.creada'       => 'Categoría creada',
        'catalogo.categoria.actualizada'  => 'Categoría modificada',
        'catalogo.producto.creado'        => 'Producto creado',
        'catalogo.producto.actualizado'   => 'Producto modificado',
        'catalogo.producto.eliminado'     => 'Producto eliminado',
        'catalogo.publicacion.creada'     => 'Producto publicado en temporada',
        'catalogo.publicacion.actualizada' => 'Publicación modificada (precio o estado)',
        'catalogo.variante.creada'        => 'Variante creada',
        'catalogo.variante.actualizada'   => 'Variante modificada',
        'catalogo.disponibilidad.creada'  => 'Disponibilidad de variante registrada',
        'catalogo.disponibilidad.actualizada' => 'Disponibilidad de variante cambiada',
        'catalogo.imagen.agregada'        => 'Imagen de producto agregada',
        'catalogo.imagen.principal'       => 'Imagen principal cambiada',
        'catalogo.imagen.eliminada'       => 'Imagen de producto eliminada',
        'importacion_catalogo.cargada'    => 'Catálogo para importar subido',
        'importacion_catalogo.eliminada'  => 'Catálogo para importar eliminado',
        'importacion_catalogo.procesada'  => 'Catálogo leído por la IA',
        'importacion_catalogo.relleno_en_lote' => 'Campo completado en lote',
        'importacion_catalogo.aprobada'   => 'Importación de catálogo aprobada',
    ];

    /** Primera parte del nombre de la acción => área. */
    public const AREAS = [
        'pedido'               => 'Pedidos',
        'pago'                 => 'Pagos',
        'mercado_pago'         => 'Pagos',
        'suscripcion'          => 'Suscripción',
        'stock'                => 'Stock',
        'venta_mostrador'      => 'Ventas de mostrador',
        'oferta'               => 'Precios',
        'catalogo'             => 'Catálogo',
        'importacion_catalogo' => 'Catálogo',
        'password'             => 'Seguridad',
        'linea_extra'          => 'Configuración',
        'distribuidora'        => 'Configuración',
    ];

    public static function etiqueta(string $accion): string
    {
        return self::ETIQUETAS[$accion] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $accion));
    }

    public static function area(string $accion): string
    {
        return self::AREAS[Str::before($accion, '.')] ?? 'Otros';
    }

    /** Las primeras partes de nombre que caen en un área. */
    public static function prefijosDe(string $area): array
    {
        return array_keys(array_filter(self::AREAS, fn ($nombre) => $nombre === $area));
    }

    /** Áreas de las acciones dadas, sin repetir y en orden. */
    public static function areasDe(Collection $acciones): Collection
    {
        return $acciones->map(fn ($accion) => self::area($accion))->unique()->sort()->values();
    }
}
