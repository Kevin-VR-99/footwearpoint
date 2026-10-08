<?php

namespace App\Services\MercadoPago;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    // ---------------------------------------------------------------
    // TG-226 (G7) — Checkout Pro con el token de la distribuidora.
    //
    // Todas estas llamadas usan el access token que la distribuidora dio al
    // conectar su cuenta (G6), así que el dinero llega a SU cuenta.
    // ---------------------------------------------------------------

    /**
     * Crea la preferencia de Checkout Pro.
     *
     * @return array{id: string, init_point: string}
     */
    public function crearPreferencia(string $token, array $preferencia): array
    {
        $datos = $this->llamar('crear la preferencia', fn () => $this->conToken($token)
            ->post($this->config('url_api').'/checkout/preferences', $preferencia));

        return $this->preferenciaValida($datos, 'crear la preferencia');
    }

    /** @return array{id: string, init_point: string} */
    public function obtenerPreferencia(string $token, string $preferenciaId): array
    {
        $datos = $this->llamar('consultar la preferencia', fn () => $this->conToken($token)
            ->get($this->config('url_api').'/checkout/preferences/'.rawurlencode($preferenciaId)));

        return $this->preferenciaValida($datos, 'consultar la preferencia');
    }

    /** Deja de aceptar pagos en una preferencia (la vence desde ya). */
    public function expirarPreferencia(string $token, string $preferenciaId): void
    {
        $this->llamar('vencer la preferencia', fn () => $this->conToken($token)
            ->put($this->config('url_api').'/checkout/preferences/'.rawurlencode($preferenciaId), [
                'expires'            => true,
                'expiration_date_to' => now()->format('Y-m-d\TH:i:s.vP'),
            ]));
    }

    /**
     * Pagos de Mercado Pago con esa external_reference, del más nuevo al más
     * viejo.
     *
     * @return list<array>
     */
    public function buscarPagos(string $token, string $externalReference): array
    {
        $datos = $this->llamar('buscar los pagos', fn () => $this->conToken($token)
            ->get($this->config('url_api').'/v1/payments/search', [
                'external_reference' => $externalReference,
                'sort'               => 'date_created',
                'criteria'           => 'desc',
            ]));

        $resultados = $datos['results'] ?? [];

        return is_array($resultados) ? array_values(array_filter($resultados, 'is_array')) : [];
    }

    /** Un pago de Mercado Pago por su id (lo usará el aviso de G9). */
    public function obtenerPago(string $token, string $pagoId): array
    {
        return $this->llamar('consultar el pago', fn () => $this->conToken($token)
            ->get($this->config('url_api').'/v1/payments/'.rawurlencode($pagoId)));
    }

    private function conToken(string $token): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 10));
    }

    /**
     * Hace la llamada y traduce cualquier falla a un mensaje para el usuario.
     * Al log solo va qué se intentaba, el código HTTP y el código de error
     * de Mercado Pago: nunca el token ni el cuerpo.
     *
     * @param  callable(): Response  $peticion
     *
     * @throws MercadoPagoException
     */
    private function llamar(string $accion, callable $peticion): array
    {
        try {
            $respuesta = $peticion();
        } catch (ConnectionException) {
            report(new RuntimeException("Mercado Pago no respondió al {$accion}."));

            throw MercadoPagoException::con(MercadoPagoException::SIN_RESPUESTA);
        }

        if ($respuesta->failed()) {
            $codigoError = $respuesta->json('error');
            $codigoError = is_string($codigoError) && $codigoError !== '' ? $codigoError : 'sin_codigo';

            report(new RuntimeException(
                "Mercado Pago rechazó {$accion} (HTTP {$respuesta->status()}, error: {$codigoError})."
            ));

            throw MercadoPagoException::con(match (true) {
                // Token vencido o revocado: la distribuidora debe reconectar.
                in_array($respuesta->status(), [401, 403], true) => MercadoPagoException::CUENTA_DESCONECTADA,
                $respuesta->serverError() => MercadoPagoException::SIN_RESPUESTA,
                default => MercadoPagoException::NO_SE_PUDO_COBRAR,
            });
        }

        $datos = $respuesta->json();

        return is_array($datos) ? $datos : [];
    }

    /** @return array{id: string, init_point: string} */
    private function preferenciaValida(array $datos, string $accion): array
    {
        $id = $datos['id'] ?? null;
        $initPoint = $datos['init_point'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($initPoint) || ! str_starts_with($initPoint, 'https://')) {
            report(new RuntimeException("Mercado Pago respondió sin id o sin init_point al {$accion}."));

            throw MercadoPagoException::con(MercadoPagoException::NO_SE_PUDO_COBRAR);
        }

        return ['id' => $id, 'init_point' => $initPoint];
    }

    private function config(string $clave, mixed $porDefecto = null): mixed
    {
        return config("services.mercadopago.{$clave}", $porDefecto);
    }
}
