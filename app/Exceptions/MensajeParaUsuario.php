<?php

namespace App\Exceptions;

/**
 * TG-224 (G3) — Marca las excepciones cuyo mensaje está escrito para el
 * usuario final (en español, sin detalles técnicos).
 *
 * Solo estas excepciones pueden mostrar su getMessage() en pantalla o en la
 * API. Cualquier otra se reporta al log y el usuario ve un mensaje genérico
 * (ver App\Support\MensajeError y App\Exceptions\RespuestaErrorApi).
 */
interface MensajeParaUsuario
{
}
