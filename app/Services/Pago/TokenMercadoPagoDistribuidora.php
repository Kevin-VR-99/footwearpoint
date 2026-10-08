<?php

namespace App\Services\Pago;

use App\Models\ConfiguracionDistribuidora;
use App\Services\MercadoPago\MercadoPagoException;
use App\Support\Tenant;

/**
 * TG-226 (G7) — El access token con el que se cobra a nombre de una
 * distribuidora (el que dio al conectar su cuenta en G6).
 *
 * Nunca sale de aquí hacia la respuesta: solo se pasa a ClienteMercadoPago.
 */
class TokenMercadoPagoDistribuidora
{
    /**
     * @return array{token: string, configuracion: ConfiguracionDistribuidora}
     *
     * @throws MercadoPagoException si la distribuidora no puede cobrar con MP.
     */
    public function para(int $distribuidoraId): array
    {
        $configuracion = Tenant::forzar($distribuidoraId, fn () => ConfiguracionDistribuidora::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->first());

        // mercadoPagoAccessToken() regresa null (y lo reporta) si el token no
        // se puede descifrar: para quien paga es lo mismo que "no conectada".
        $token = $configuracion?->mp_conectado_at !== null ? $configuracion->mercadoPagoAccessToken() : null;

        if ($token === null) {
            throw MercadoPagoException::con(MercadoPagoException::NO_ACEPTA_MP);
        }

        if ($configuracion->mercadoPagoVenceAprox()?->isPast()) {
            throw MercadoPagoException::con(MercadoPagoException::CUENTA_DESCONECTADA);
        }

        return ['token' => $token, 'configuracion' => $configuracion];
    }
}
