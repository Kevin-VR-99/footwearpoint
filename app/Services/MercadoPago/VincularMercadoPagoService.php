<?php

namespace App\Services\MercadoPago;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TG-225 (G6) — Conecta la cuenta de Mercado Pago de una distribuidora con el
 * flujo oficial (OAuth, código de autorización + PKCE S256 + state).
 *
 * 1. iniciar(): guarda en la sesión un state y un code_verifier de un solo
 *    uso (10 minutos) y devuelve la URL oficial de autorización.
 * 2. completar(): en el callback valida el state contra la sesión, cambia el
 *    código por las credenciales y las guarda en configuraciones_distribuidora
 *    (el token cifrado con la APP_KEY por el cast 'encrypted').
 *
 * No se guarda el refresh token (no hay columna en Ola 1): el token dura 180
 * días y, al vencer, la distribuidora vuelve a conectar.
 */
class VincularMercadoPagoService
{
    public const CLAVE_SESION = 'mercado_pago_oauth';

    public const MINUTOS_VIGENCIA_SOLICITUD = 10;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return string URL de Mercado Pago a la que se redirige a la distribuidora.
     *
     * @throws MercadoPagoException si la aplicación de MP no está configurada.
     */
    public function iniciar(Usuario $usuario, int $distribuidoraId): string
    {
        if (! $this->cliente->configurado()) {
            throw MercadoPagoException::con(MercadoPagoException::NO_CONFIGURADO);
        }

        $state = Str::random(40);
        $verifier = $this->cliente->usaPkce() ? Str::random(64) : null;

        session()->put(self::CLAVE_SESION, [
            'state'            => $state,
            'verifier'         => $verifier,
            'usuario_id'       => $usuario->getKey(),
            'distribuidora_id' => $distribuidoraId,
            'expira'           => now()->addMinutes(self::MINUTOS_VIGENCIA_SOLICITUD)->getTimestamp(),
        ]);

        return $this->cliente->urlAutorizacion($state, $verifier !== null ? self::codeChallenge($verifier) : null);
    }

    /**
     * Procesa la respuesta de Mercado Pago en el callback.
     *
     * @param  array  $query  Parámetros del callback (code, state o error).
     *
     * @throws MercadoPagoException con un mensaje listo para la distribuidora.
     */
    public function completar(array $query, Usuario $usuario, int $distribuidoraId): ConfiguracionDistribuidora
    {
        // Un solo uso: se saca de la sesión pase lo que pase.
        $solicitud = session()->pull(self::CLAVE_SESION);

        if (isset($query['error'])) {
            throw MercadoPagoException::con(
                $query['error'] === 'access_denied' ? MercadoPagoException::CANCELADA : MercadoPagoException::RECHAZADA
            );
        }

        $state = $query['state'] ?? null;
        $codigo = $query['code'] ?? null;

        if (! is_array($solicitud)
            || ! is_string($state) || ! is_string($solicitud['state'] ?? null)
            || ! hash_equals($solicitud['state'], $state)
            || (int) ($solicitud['usuario_id'] ?? 0) !== (int) $usuario->getKey()
            || (int) ($solicitud['distribuidora_id'] ?? 0) !== $distribuidoraId
            || (int) ($solicitud['expira'] ?? 0) < now()->getTimestamp()
            || ! is_string($codigo) || $codigo === '') {
            throw MercadoPagoException::con(MercadoPagoException::SOLICITUD_INVALIDA);
        }

        $datos = $this->cliente->intercambiarCodigo($codigo, $solicitud['verifier'] ?? null);

        // En sandbox solo se aceptan cuentas de prueba.
        if ($this->cliente->esSandbox() && ($datos['live_mode'] ?? false) === true) {
            throw MercadoPagoException::con(MercadoPagoException::CUENTA_REAL);
        }

        $cuentaId = (string) $datos['user_id'];

        // Una cuenta de MP no puede cobrar para dos distribuidoras (los pagos
        // se atribuyen por cuenta). Es la única consulta que mira otras
        // distribuidoras: ConfiguracionDistribuidora tiene el scope de tenant,
        // así que se pregunta con DB::table y solo si EXISTE, sin cargar
        // ningún dato ajeno (en lugar de withoutGlobalScopes sobre el modelo).
        $enUso = DB::table('configuraciones_distribuidora')
            ->where('mercado_pago_account_id', $cuentaId)
            ->where('distribuidora_id', '!=', $distribuidoraId)
            ->exists();

        if ($enUso) {
            throw MercadoPagoException::con(MercadoPagoException::CUENTA_EN_USO);
        }

        $publicKey = $datos['public_key'] ?? null;
        $publicKey = is_string($publicKey) && $publicKey !== '' && mb_strlen($publicKey) <= 190 ? $publicKey : null;

        return DB::transaction(function () use ($distribuidoraId, $datos, $cuentaId, $publicKey) {
            $configuracion = ConfiguracionDistribuidora::query()
                ->where('distribuidora_id', $distribuidoraId)
                ->lockForUpdate()
                ->firstOrFail();

            $cuentaAnterior = $configuracion->mercado_pago_account_id;

            $configuracion->update([
                'mp_access_token'         => $datos['access_token'],
                'mp_public_key'           => $publicKey,
                'mercado_pago_account_id' => $cuentaId,
                'mp_conectado_at'         => now(),
            ]);

            // Sin tokens en la bitácora: solo qué cuenta se conectó.
            $this->auditoria->ejecutar(
                'mercado_pago.conectado',
                'configuracion_distribuidora',
                $configuracion->id,
                ['cuenta_mercado_pago' => $cuentaAnterior],
                [
                    'cuenta_mercado_pago' => $cuentaId,
                    'modo'                => $this->cliente->esSandbox() ? 'sandbox' : 'produccion',
                ],
            );

            return $configuracion;
        });
    }

    /**
     * Estado de la conexión para mostrarlo en pantalla. Nunca incluye el token.
     *
     * @return array{configurado: bool, modo_prueba: bool, conectado: bool, ilegible: bool,
     *               cuenta_id: ?string, conectado_at: ?\Illuminate\Support\Carbon,
     *               vence_aprox: ?\Illuminate\Support\Carbon, por_vencer: bool, vencido: bool}
     */
    public function estado(ConfiguracionDistribuidora $configuracion): array
    {
        $conectado = $configuracion->tieneMercadoPago();
        $vence = $conectado ? $configuracion->mercadoPagoVenceAprox() : null;
        $vencido = $vence !== null && $vence->isPast();

        return [
            'configurado'  => $this->cliente->configurado(),
            'modo_prueba'  => $this->cliente->esSandbox(),
            'conectado'    => $conectado,
            'ilegible'     => ! $conectado && $configuracion->mp_conectado_at !== null,
            'cuenta_id'    => $conectado ? $configuracion->mercado_pago_account_id : null,
            'conectado_at' => $conectado ? $configuracion->mp_conectado_at : null,
            'vence_aprox'  => $vence,
            'vencido'      => $vencido,
            'por_vencer'   => $vence !== null && ! $vencido
                && $vence->lte(now()->addDays(ConfiguracionDistribuidora::DIAS_AVISO_VENCIMIENTO_MP)),
        ];
    }

    /** code_challenge S256 = base64url(sha256(code_verifier)) sin relleno (RFC 7636). */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
