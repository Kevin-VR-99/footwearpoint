<?php

namespace App\Services\MercadoPago;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TG-225 (G6) — Las llamadas a Mercado Pago, en un solo lugar.
 *
 * Usa las credenciales de la aplicación de FootwearPoint (config
 * services.mercadopago, que sale de variables de entorno). G7 agregará aquí
 * la creación de preferencias de Checkout Pro.
 *
 * Al log solo va el código HTTP y el código de error de Mercado Pago: nunca
 * el cuerpo de la petición (lleva el client_secret) ni los tokens.
 */
class ClienteMercadoPago
{
    /** true si están las tres variables necesarias para conectar cuentas. */
    public function configurado(): bool
    {
        return filled($this->config('client_id'))
            && filled($this->config('client_secret'))
            && filled($this->config('redirect_uri'));
    }

    public function esSandbox(): bool
    {
        return $this->config('modo') !== 'produccion';
    }

    public function usaPkce(): bool
    {
        return (bool) $this->config('pkce');
    }

    /** URL de la pantalla oficial de Mercado Pago donde la distribuidora autoriza. */
    public function urlAutorizacion(string $state, ?string $codeChallenge): string
    {
        $parametros = [
            'client_id'     => $this->config('client_id'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => $this->config('redirect_uri'),
        ];

        if ($codeChallenge !== null) {
            $parametros['code_challenge'] = $codeChallenge;
            $parametros['code_challenge_method'] = 'S256';
        }

        return $this->config('url_autorizacion') . '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Cambia el código de autorización por las credenciales de la
     * distribuidora (access_token, public_key, user_id, live_mode, ...).
     *
     * @throws MercadoPagoException si Mercado Pago no responde o no acepta.
     */
    public function intercambiarCodigo(string $codigo, ?string $codeVerifier): array
    {
        $cuerpo = [
            'client_id'     => (string) $this->config('client_id'),
            'client_secret' => (string) $this->config('client_secret'),
            'grant_type'    => 'authorization_code',
            'code'          => $codigo,
            'redirect_uri'  => (string) $this->config('redirect_uri'),
            // En sandbox se piden credenciales de prueba.
            'test_token'    => $this->esSandbox(),
        ];

        if ($codeVerifier !== null) {
            $cuerpo['code_verifier'] = $codeVerifier;
        }

        try {
            $respuesta = Http::acceptJson()
                ->asJson()
                ->timeout((int) $this->config('timeout', 10))
                ->post($this->config('url_api') . '/oauth/token', $cuerpo);
        } catch (ConnectionException) {
            report(new RuntimeException('Mercado Pago no respondió al intercambiar el código de autorización (OAuth).'));

            throw MercadoPagoException::con(MercadoPagoException::SIN_RESPUESTA);
        }

        if ($respuesta->failed()) {
            $codigoError = is_string($respuesta->json('error')) ? $respuesta->json('error') : 'sin_codigo';

            report(new RuntimeException(
                "Mercado Pago rechazó el intercambio OAuth (HTTP {$respuesta->status()}, error: {$codigoError})."
            ));

            throw MercadoPagoException::con(
                $respuesta->serverError() ? MercadoPagoException::SIN_RESPUESTA : MercadoPagoException::RECHAZADA
            );
        }

        $datos = $respuesta->json();

        if (! is_array($datos) || ! is_string($datos['access_token'] ?? null) || $datos['access_token'] === ''
            || ! is_scalar($datos['user_id'] ?? null) || (string) $datos['user_id'] === '') {
            report(new RuntimeException('Mercado Pago respondió al intercambio OAuth sin access_token o sin user_id.'));

            throw MercadoPagoException::con(MercadoPagoException::RECHAZADA);
        }

        return $datos;
    }

    private function config(string $clave, mixed $porDefecto = null): mixed
    {
        return config("services.mercadopago.{$clave}", $porDefecto);
    }
}
