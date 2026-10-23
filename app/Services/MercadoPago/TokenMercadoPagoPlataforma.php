<?php

namespace App\Services\MercadoPago;

use Illuminate\Support\Facades\Cache;

/**
 * TG-230 (G11) — El access token de la cuenta de Mercado Pago de
 * FootwearPoint (MP_ACCESS_TOKEN), con el que se cobra la mensualidad de las
 * distribuidoras, y el id de esa cuenta.
 *
 * El id se pregunta una vez a Mercado Pago (GET /users/me) y se guarda en
 * caché; la llave lleva un hash del token, así que si cambia el token se
 * vuelve a preguntar. El token nunca sale de aquí hacia una respuesta.
 */
class TokenMercadoPagoPlataforma
{
    public function __construct(private readonly ClienteMercadoPago $cliente)
    {
    }

    /** true si está MP_ACCESS_TOKEN (no revisa que sirva). */
    public function configurado(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @return array{token: string, cuenta: string}
     *
     * @throws MercadoPagoException con un mensaje para la distribuidora.
     */
    public function para(): array
    {
        $token = $this->token();

        if ($token === null) {
            throw MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE);
        }

        return ['token' => $token, 'cuenta' => $this->cuenta($token)];
    }

    /**
     * ¿Esa cuenta de Mercado Pago es la de FootwearPoint? Sin token
     * configurado, no.
     *
     * Si el token de FootwearPoint ya no sirve, responde que no, para no
     * frenar los avisos de los pedidos de las distribuidoras.
     *
     * @throws MercadoPagoException si Mercado Pago no responde (temporal).
     */
    public function esLaCuenta(string $cuentaMp): bool
    {
        $token = $this->token();

        if ($token === null) {
            return false;
        }

        try {
            return $this->cuenta($token) === $cuentaMp;
        } catch (MercadoPagoException $e) {
            if ($e->getMessage() === MercadoPagoException::SIN_RESPUESTA) {
                throw $e;
            }

            return false;
        }
    }

    private function cuenta(string $token): string
    {
        $llave = 'mercado_pago.cuenta_plataforma.'.hash('sha256', $token);

        $cuenta = Cache::get($llave);

        if (is_string($cuenta) && $cuenta !== '') {
            return $cuenta;
        }

        try {
            $cuenta = $this->cliente->cuentaDelToken($token);
        } catch (MercadoPagoException $e) {
            // Token inválido o revocado: para la distribuidora es "no
            // disponible"; si Mercado Pago no respondió, se dice tal cual.
            throw $e->getMessage() === MercadoPagoException::SIN_RESPUESTA
                ? $e
                : MercadoPagoException::con(MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE);
        }

        Cache::forever($llave, $cuenta);

        return $cuenta;
    }

    /**
     * Lo que el admin general puede ver de la cuenta que cobra las mensualidades.
     * El token no sale de aquí.
     *
     * @return array{configurada: bool, consultada: bool, id?: string, nickname?: ?string, email?: ?string, nombre?: ?string}
     */
    public function identidad(): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['configurada' => false, 'consultada' => false];
        }

        $llave = 'mercado_pago.identidad_plataforma.'.hash('sha256', $token);
        $guardada = Cache::get($llave);

        if (is_array($guardada) && ($guardada['consultada'] ?? false) === true) {
            return $guardada;
        }

        try {
            $identidad = $this->cliente->identidadDelToken($token);
        } catch (MercadoPagoException) {
            return ['configurada' => true, 'consultada' => false];
        }

        $identidad['configurada'] = true;
        $identidad['consultada'] = true;
        Cache::put($llave, $identidad, now()->addHour());

        return $identidad;
    }

    private function token(): ?string
    {
        $token = config('services.mercadopago.access_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }
}
