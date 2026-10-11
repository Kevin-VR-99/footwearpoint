<?php

namespace App\Support;

use App\Exceptions\RespuestaErrorApi;
use Throwable;

/**
 * TG-264 (U4) - Qué mensaje muestra la página 403.
 *
 * Solo se muestran los mensajes propios de la app (lista explícita). Cualquier
 * otro, como los de Laravel en inglés ("Invalid signature.", "This action is
 * unauthorized."), se cambia por el mensaje genérico en español.
 * Si se agrega un abort(403, '...') nuevo que deba verse, se suma aquí.
 */
final class MensajeProhibido
{
    public const GENERICO = 'No tienes permiso para ver esta página.';

    public const PERMITIDOS = [
        RespuestaErrorApi::SIN_PERMISO,
        MensajeError::SIN_PERMISO,
        'No se pudo determinar la distribuidora.',
        'No se pudo determinar la distribuidora del usuario autenticado.',
        'No se pudo determinar a nombre de quién va el pedido.',
        'No se pudo determinar el staff del usuario autenticado.',
        'Solo el personal de la distribuidora puede entregar pedidos.',
        'Solo el administrador de la distribuidora puede ver la auditoría.',
        'Solo admin general.',
        'No autorizado.',
    ];

    public static function para(?Throwable $e): string
    {
        $mensaje = $e?->getMessage() ?? '';

        return in_array($mensaje, self::PERMITIDOS, true) ? $mensaje : self::GENERICO;
    }
}
