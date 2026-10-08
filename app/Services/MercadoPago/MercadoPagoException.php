<?php

namespace App\Services\MercadoPago;

use App\Exceptions\MensajeParaUsuario;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    // --- TG-226 (G7): pagar el anticipo con Checkout Pro ---

    public const NO_ACEPTA_MP = 'Esta distribuidora todavía no recibe pagos con Mercado Pago. Puedes pagar tu anticipo en mostrador.';

    public const CUENTA_DESCONECTADA = 'La distribuidora necesita volver a conectar su cuenta de Mercado Pago. Mientras tanto, puedes pagar tu anticipo en mostrador.';

    public const NO_SE_PUDO_COBRAR = 'No se pudo preparar el pago con Mercado Pago. Intenta de nuevo en unos minutos o paga tu anticipo en mostrador.';

    public const SOLO_CLIENTE_DIRECTO = 'El anticipo con Mercado Pago es para pedidos de cliente directo.';

    public const PEDIDO_BORRADOR = 'Envía tu pedido antes de pagar el anticipo.';

    public const PEDIDO_CERRADO = 'Este pedido ya no admite pagos.';

    public const SIN_ANTICIPO_PENDIENTE = 'Este pedido ya no tiene anticipo pendiente.';

    public const PAGO_EN_PREPARACION = 'Ya estamos preparando tu pago. Espera unos segundos e intenta de nuevo.';

    public const SIN_PAGO_POR_CONFIRMAR = 'Este pedido no tiene un pago con Mercado Pago por confirmar.';

    public function __construct(string $mensaje, private int $estadoHttp = 422)
    {
        parent::__construct($mensaje);
    }

    public static function con(string $mensaje, ?int $estadoHttp = null): self
    {
        // Cuando Mercado Pago no responde no es culpa de quien pide: 503.
        return new self($mensaje, $estadoHttp ?? ($mensaje === self::SIN_RESPUESTA ? 503 : 422));
    }

    /**
     * En la API responde con el formato de error de siempre
     * ({ "message": "..." }). En la web regresa false y cada pantalla decide
     * cómo mostrar el mensaje (Laravel sigue con su manejo normal).
     */
    public function render(Request $request): JsonResponse|false
    {
        if (! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], $this->estadoHttp);
    }
}
