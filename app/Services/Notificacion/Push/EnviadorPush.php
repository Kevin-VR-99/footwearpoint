<?php

namespace App\Services\Notificacion\Push;

/**
 * Manda una notificación push a uno o varios celulares (TG-135).
 *
 * Es una interfaz para que el resto del sistema no dependa directamente de
 * Firebase: en producción la implementa EnviadorPushFirebase, y en las
 * pruebas se reemplaza por uno falso que no sale a internet.
 */
interface EnviadorPush
{
    /**
     * @param  string[]  $tokens  Tokens de dispositivos_fcm.
     * @param  array<string, string>  $datos  Datos extra para la app (todos texto).
     * @return string[] Los tokens que Firebase reporta como inválidos o
     *                  desconocidos (app desinstalada, token vencido), para
     *                  borrarlos de la tabla.
     */
    public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array;
}
