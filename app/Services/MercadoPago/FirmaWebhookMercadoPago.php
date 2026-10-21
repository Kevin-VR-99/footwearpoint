<?php

namespace App\Services\MercadoPago;

/**
 * TG-228 (G9) — Revisa la firma (x-signature) de un aviso de Mercado Pago.
 *
 * Mercado Pago firma cada webhook con la clave secreta que da el panel de la
 * aplicación (Webhooks > Configurar notificaciones). La firma es un
 * HMAC-SHA256 en hexadecimal de este texto:
 *
 *   id:{data.id de la URL, en minúsculas};request-id:{x-request-id};ts:{ts};
 *
 * Si data.id o x-request-id no vienen, su parte se quita. La clave solo vive
 * en la variable de entorno MP_WEBHOOK_SECRET.
 *
 * La firma es una capa extra: lo que decide si un pago se aplica es lo que
 * contesta la API de Mercado Pago con el token de la distribuidora.
 */
class FirmaWebhookMercadoPago
{
    public const VALIDA = 'valida';

    public const INVALIDA = 'invalida';

    /** El aviso no trae firma (por ejemplo, el formato viejo IPN). */
    public const SIN_FIRMA = 'sin_firma';

    /** Trae firma, pero FootwearPoint no tiene la clave configurada. */
    public const SIN_CLAVE = 'sin_clave';

    /** La firma cuadra (o no se pudo revisar), pero su ts es de hace más de 5 minutos: posible repetición. */
    public const VENCIDA = 'vencida';

    /** Cuánto puede tener de antigüedad (o de adelanto) el ts de la firma. */
    public const TOLERANCIA_SEGUNDOS = 300;

    public function revisar(?string $firma, ?string $requestId, ?string $dataId): string
    {
        if ($firma === null || trim($firma) === '') {
            return self::SIN_FIRMA;
        }

        $clave = (string) config('services.mercadopago.webhook_secret', '');

        if ($clave === '') {
            return self::SIN_CLAVE;
        }

        $partes = [];
        foreach (explode(',', $firma) as $parte) {
            [$nombre, $valor] = array_pad(explode('=', $parte, 2), 2, '');
            $partes[strtolower(trim($nombre))] = trim($valor);
        }

        $ts = $partes['ts'] ?? '';
        $v1 = strtolower($partes['v1'] ?? '');

        if ($ts === '' || $v1 === '' || ! ctype_digit($ts)) {
            return self::INVALIDA;
        }

        $esperada = hash_hmac('sha256', self::manifiesto($dataId, $requestId, $ts), $clave);

        if (! hash_equals($esperada, $v1)) {
            return self::INVALIDA;
        }

        return self::tsVigente($ts) ? self::VALIDA : self::VENCIDA;
    }

    /**
     * Mercado Pago manda ts en milisegundos (a veces en segundos). Se acepta
     * si está a menos de 5 minutos de la hora actual, hacia atrás o adelante.
     */
    public static function tsVigente(string $ts): bool
    {
        $segundos = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;

        return abs(now()->getTimestamp() - $segundos) <= self::TOLERANCIA_SEGUNDOS;
    }

    /** El texto que firma Mercado Pago. */
    public static function manifiesto(?string $dataId, ?string $requestId, string $ts): string
    {
        $manifiesto = '';

        if ($dataId !== null && $dataId !== '') {
            $manifiesto .= 'id:'.strtolower($dataId).';';
        }

        if ($requestId !== null && $requestId !== '') {
            $manifiesto .= 'request-id:'.$requestId.';';
        }

        return $manifiesto.'ts:'.$ts.';';
    }
}
