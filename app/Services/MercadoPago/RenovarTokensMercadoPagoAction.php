<?php

namespace App\Services\MercadoPago;

use App\Mail\ReconectarMercadoPagoMail;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renueva los tokens OAuth de Mercado Pago de las distribuidoras antes de que
 * venzan (duran ~180 días). Lo corre un cron semanal de Railway con
 * `php artisan mercado-pago:renovar-tokens`.
 *
 * - Con refresh token: pide uno nuevo (grant_type=refresh_token) y guarda el
 *   access token, el refresh token y el vencimiento que regresa Mercado Pago.
 * - Sin refresh token (conexiones hechas antes de guardarlo) o si Mercado Pago
 *   rechaza la renovación: avisa a los administradores de la distribuidora
 *   (campana y, si se pide, correo) para que vuelvan a conectar su cuenta.
 *   El aviso se repite como máximo una vez cada DIAS_ENTRE_AVISOS.
 *
 * Nunca escribe tokens en el log.
 */
class RenovarTokensMercadoPagoAction
{
    public const DIAS_ANTES_DE_VENCER = 30;

    public const DIAS_ENTRE_AVISOS = 6;

    public const TIPO_NOTIFICACION = 'mercado_pago_reconectar';

    public function __construct(private readonly ClienteMercadoPago $cliente)
    {
    }

    /**
     * @return array{revisadas: int, renovadas: int, avisadas: int, fallidas: int}
     */
    public function ejecutar(bool $simular = false, bool $conCorreo = true): array
    {
        $resultado = ['revisadas' => 0, 'renovadas' => 0, 'avisadas' => 0, 'fallidas' => 0];
        $limite = now()->addDays(self::DIAS_ANTES_DE_VENCER);

        $configuraciones = ConfiguracionDistribuidora::withoutGlobalScopes()
            ->whereNotNull('mp_conectado_at')
            ->whereNotNull('mp_access_token')
            ->orderBy('id')
            ->get();

        foreach ($configuraciones as $configuracion) {
            $vence = $configuracion->mercadoPagoVenceAprox();

            if ($vence === null || $vence->gt($limite)) {
                continue;
            }

            $resultado['revisadas']++;

            if ($simular) {
                continue;
            }

            $refresh = $configuracion->mercadoPagoRefreshToken();

            if ($refresh === null) {
                Log::warning('Mercado Pago: conexión sin refresh token cerca de vencer; se pide reconectar.', [
                    'distribuidora_id' => $configuracion->distribuidora_id,
                    'vence'            => $vence->toDateString(),
                ]);

                if ($this->avisar($configuracion, $vence, $conCorreo)) {
                    $resultado['avisadas']++;
                }

                continue;
            }

            try {
                $datos = $this->cliente->renovarToken($refresh);
            } catch (Throwable $e) {
                $resultado['fallidas']++;

                Log::error('Mercado Pago: no se pudo renovar el token de la distribuidora.', [
                    'distribuidora_id' => $configuracion->distribuidora_id,
                    'motivo'           => $e->getMessage(),
                ]);

                if ($this->avisar($configuracion, $vence, $conCorreo)) {
                    $resultado['avisadas']++;
                }

                continue;
            }

            $configuracion->forceFill([
                'mp_access_token'        => $datos['access_token'],
                'mp_refresh_token'       => VincularMercadoPagoService::textoONull($datos['refresh_token'] ?? null) ?? $refresh,
                'mp_token_expira_en'     => VincularMercadoPagoService::expiraEn($datos['expires_in'] ?? null)
                    ?? now()->addDays(ConfiguracionDistribuidora::DIAS_VIGENCIA_TOKEN_MP),
                'mp_aviso_reconexion_at' => null,
            ])->save();

            $resultado['renovadas']++;

            Log::info('Mercado Pago: token renovado.', ['distribuidora_id' => $configuracion->distribuidora_id]);
        }

        return $resultado;
    }

    /** @return bool true si se mandó el aviso (false si ya se avisó hace poco). */
    private function avisar(ConfiguracionDistribuidora $configuracion, \Illuminate\Support\Carbon $vence, bool $conCorreo): bool
    {
        $ultimo = $configuracion->mp_aviso_reconexion_at;

        if ($ultimo !== null && $ultimo->gt(now()->subDays(self::DIAS_ENTRE_AVISOS))) {
            return false;
        }

        $distribuidoraId = (int) $configuracion->distribuidora_id;
        $vencido = $vence->isPast();

        $titulo = $vencido
            ? 'Tu conexión con Mercado Pago venció'
            : 'Vuelve a conectar tu cuenta de Mercado Pago';
        $mensaje = $vencido
            ? 'La autorización de tu cuenta de Mercado Pago venció y tus clientes no pueden pagarte en línea. '
                .'Entra a Configuración → Mercado Pago y vuelve a conectarla.'
            : 'La autorización de tu cuenta de Mercado Pago vence alrededor del '.$vence->format('d/m/Y')
                .' y no se pudo renovar sola. Entra a Configuración → Mercado Pago y vuelve a conectarla para seguir cobrando en línea.';

        Tenant::forzar($distribuidoraId, function () use ($distribuidoraId, $configuracion, $titulo, $mensaje, $conCorreo) {
            $administradores = DistribuidoraStaff::with('usuario')
                ->where('distribuidora_id', $distribuidoraId)
                ->where('tipo', 'administrador')
                ->where('estado', 'activo')
                ->whereNotNull('usuario_id')
                ->get();

            foreach ($administradores as $staff) {
                Notificacion::create([
                    'usuario_id'       => (int) $staff->usuario_id,
                    'distribuidora_id' => $distribuidoraId,
                    'tipo'             => self::TIPO_NOTIFICACION,
                    'titulo'           => $titulo,
                    'mensaje'          => $mensaje,
                    'leida_at'         => null,
                    'entidad_tipo'     => 'configuracion_distribuidora',
                    'entidad_id'       => $configuracion->id,
                ]);

                $correo = trim((string) $staff->usuario?->email);

                if ($conCorreo && $correo !== '') {
                    try {
                        Mail::to($correo)->send(new ReconectarMercadoPagoMail($titulo, $mensaje));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
        });

        $configuracion->forceFill(['mp_aviso_reconexion_at' => now()])->save();

        return true;
    }
}
