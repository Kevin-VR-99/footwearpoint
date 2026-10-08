<?php

namespace App\Services\MercadoPago;

use App\Exceptions\MensajeParaUsuario;
use Exception;

/**
 * TG-225 (G6) — Algo impidió conectar la cuenta de Mercado Pago. El mensaje
 * está escrito para la distribuidora (en español, sin detalles técnicos); el
 * detalle, cuando lo hay, ya se reportó al log.
 */
class MercadoPagoException extends Exception implements MensajeParaUsuario
{
    public const NO_CONFIGURADO = 'La conexión con Mercado Pago todavía no está configurada en FootwearPoint. Avísale al equipo de FootwearPoint.';

    public const SOLICITUD_INVALIDA = 'No se pudo confirmar la conexión con Mercado Pago o ya expiró. Vuelve a intentarlo desde aquí.';

    public const CANCELADA = 'Cancelaste la conexión con Mercado Pago. Puedes intentarlo de nuevo cuando quieras.';

    public const SIN_RESPUESTA = 'Mercado Pago no respondió. Intenta de nuevo en unos minutos.';

    public const RECHAZADA = 'Mercado Pago no aceptó la conexión. Vuelve a intentarlo; si sigue fallando, avísale al equipo de FootwearPoint.';

    public const CUENTA_REAL = 'FootwearPoint está en modo de prueba: conecta una cuenta de prueba de Mercado Pago, no una cuenta real.';

    public const CUENTA_EN_USO = 'Esa cuenta de Mercado Pago ya está conectada a otra distribuidora. Cada distribuidora debe usar su propia cuenta.';

    public static function con(string $mensaje): self
    {
        return new self($mensaje);
    }
}
