<?php

namespace App\Services\MercadoPago;

use App\Models\ConfiguracionDistribuidora;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta GET /users/me con el access token del vendedor y guarda el
 * nombre, el correo y el usuario (nickname) de la cuenta conectada.
 *
 * Se llama al conectar, al renovar el token y, de forma perezosa, la
 * primera vez que se abre la pestaña si la cuenta ya estaba conectada.
 * Si la API falla, no se corta la conexión: la pantalla muestra
 * «No disponible». Nunca escribe tokens en el log.
 */
class SincronizarIdentidadMercadoPagoAction
{
    public const LARGO_NOMBRE = 150;

    public const LARGO_CORREO = 190;

    public const LARGO_USUARIO = 190;

    public function __construct(private readonly ClienteMercadoPago $cliente)
    {
    }

    public function ejecutar(ConfiguracionDistribuidora $configuracion, bool $forzar = false): bool
    {
        if (! $configuracion->tieneMercadoPago()) {
            return false;
        }

        if (! $forzar && $configuracion->mp_identidad_consultada_at !== null) {
            return false;
        }

        $token = $configuracion->mercadoPagoAccessToken();

        if ($token === null) {
            return false;
        }

        try {
            $identidad = $this->cliente->identidadDelToken($token);
        } catch (Throwable $e) {
            Log::warning('Mercado Pago: no se pudo consultar el titular de la cuenta.', [
                'distribuidora_id' => $configuracion->distribuidora_id,
                'motivo'           => $e->getMessage(),
            ]);

            return false;
        }

        $cuentaId = (string) $configuracion->mercado_pago_account_id;
        $idRemoto = $identidad['id'] ?? null;

        if (is_string($idRemoto) && $idRemoto !== '' && $cuentaId !== '' && ! hash_equals($cuentaId, $idRemoto)) {
            Log::warning('Mercado Pago: el id de /users/me no coincide con la cuenta conectada.', [
                'distribuidora_id' => $configuracion->distribuidora_id,
            ]);

            return false;
        }

        $configuracion->forceFill([
            'mp_nombre'                  => self::acortar($identidad['nombre'] ?? null, self::LARGO_NOMBRE),
            'mp_email'                   => self::acortar($identidad['email'] ?? null, self::LARGO_CORREO),
            'mp_nickname'                => self::acortar($identidad['nickname'] ?? null, self::LARGO_USUARIO),
            'mp_identidad_consultada_at' => now(),
        ])->save();

        return true;
    }

    private static function acortar(mixed $valor, int $largo): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        return mb_strlen($valor) > $largo ? mb_substr($valor, 0, $largo) : $valor;
    }
}
