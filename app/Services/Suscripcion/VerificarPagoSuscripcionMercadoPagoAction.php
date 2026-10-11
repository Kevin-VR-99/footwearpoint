<?php

namespace App\Services\Suscripcion;

use App\Models\Pago;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TG-230 (G11) — "¿Ya se pagó la mensualidad?" Se pregunta directo a Mercado
 * Pago con el token de FootwearPoint, igual que VerificarPagoMercadoPagoAction
 * con los pedidos (G7):
 *   1. GET /v1/payments/{payment_id}, si se tiene (el regreso de Mercado Pago lo trae);
 *   2. los pagos de las órdenes de la preferencia;
 *   3. la búsqueda por external_reference, si lo anterior no encontró nada.
 * Lo que traiga la URL solo dice dónde preguntar; lo que decide es la API y
 * AplicarPagoSuscripcionMercadoPagoAction, que revisa que sea ESTE pago.
 */
class VerificarPagoSuscripcionMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const PENDIENTE = 'pendiente';

    public const RECHAZADO = 'rechazado';

    public const VENCIDO = 'vencido';

    public const NO_CUADRA = 'no_cuadra';

    private const MAX_PAGOS_POR_ORDEN = 5;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly AplicarPagoSuscripcionMercadoPagoAction $aplicar,
    ) {
    }

    /**
     * El pago de mensualidad pendiente más reciente de la distribuidora.
     *
     * @throws MercadoPagoException si no hay nada que verificar o MP no responde.
     */
    public function ejecutar(int $distribuidoraId, ?string $pagoMpId = null, string $origen = 'panel'): string
    {
        $pendiente = Tenant::forzar($distribuidoraId, fn () => Pago::query()
            ->where('tipo', 'suscripcion')
            ->where('metodo', 'mercado_pago')
            ->where('estado', 'pendiente')
            ->whereNotNull('preferencia_externa')
            ->latest('id')
            ->first());

        if ($pendiente === null) {
            throw MercadoPagoException::con(MercadoPagoException::SIN_PAGO_SUSCRIPCION_POR_CONFIRMAR);
        }

        return $this->verificarPago($pendiente, $pagoMpId, $origen);
    }

    /** Un pago en particular (el regreso ya sabe cuál es por la external_reference). */
    public function verificarPago(Pago $pendiente, ?string $pagoMpId = null, string $origen = 'panel'): string
    {
        if ($pendiente->estado === 'aplicado') {
            return self::APLICADO;
        }

        ['token' => $token] = $this->plataforma->para();

        $revision = ['vistos' => [], 'estados' => [], 'no_cuadra' => null, 'consultas' => [], 'aplicado' => null];

        if ($this->porId($pendiente, $token, $pagoMpId, $revision)
            || $this->porOrdenes($pendiente, $token, $revision)
            || $this->porBusqueda($pendiente, $token, $revision)) {
            Log::info('Mercado Pago: pago de mensualidad confirmado y aplicado.', [
                'pago_id' => $pendiente->id,
                'origen'  => $origen,
                'metodo'  => $revision['aplicado'],
            ]);

            return self::APLICADO;
        }

        $resultado = match (true) {
            $revision['no_cuadra'] !== null => self::NO_CUADRA,
            $pendiente->venceMercadoPagoAt()->isPast() => self::VENCIDO,
            collect($revision['estados'])->contains(fn ($s) => in_array($s, ['rejected', 'cancelled'], true)) => self::RECHAZADO,
            default => self::PENDIENTE,
        };

        if ($resultado === self::VENCIDO) {
            Tenant::forzar((int) $pendiente->distribuidora_id, fn () => DB::transaction(function () use ($pendiente) {
                $actual = Pago::query()->whereKey($pendiente->id)->lockForUpdate()->first();

                if ($actual?->estado === 'pendiente') {
                    $actual->update(['estado' => 'fallido']);
                }
            }));
        }

        Log::warning('Mercado Pago: el pago de mensualidad no se aplicó al verificar.', [
            'pago_id'   => $pendiente->id,
            'origen'    => $origen,
            'resultado' => $resultado,
            'motivo'    => $revision['no_cuadra'],
            'consultas' => $revision['consultas'],
        ]);

        return $resultado;
    }

    private function porId(Pago $pendiente, string $token, ?string $pagoMpId, array &$revision): bool
    {
        if ($pagoMpId === null || preg_match('/^[0-9]{1,20}$/', $pagoMpId) !== 1) {
            return false;
        }

        $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $pagoMpId);
        $revision['consultas'][] = ['metodo' => 'payment_id', 'payment_id' => $pagoMpId, 'status' => $pagoMp['status'] ?? null];

        if ($pagoMp === null) {
            $revision['no_cuadra'] ??= 'payment_id no encontrado con el token de FootwearPoint';

            return false;
        }

        return $this->revisar($pendiente, $pagoMp, 'payment_id', true, $revision);
    }

    private function porOrdenes(Pago $pendiente, string $token, array &$revision): bool
    {
        ['pagos' => $ids] = $this->cliente->pagosDeLaPreferencia($token, (string) $pendiente->preferencia_externa);

        $ids = array_slice(array_values(array_filter($ids, fn ($id) => ! isset($revision['vistos'][$id]))), 0, self::MAX_PAGOS_POR_ORDEN);

        foreach ($ids as $id) {
            $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $id);

            if ($pagoMp !== null && $this->revisar($pendiente, $pagoMp, 'merchant_order', false, $revision)) {
                return true;
            }
        }

        $revision['consultas'][] = ['metodo' => 'merchant_order', 'pagos' => $ids];

        return false;
    }

    private function porBusqueda(Pago $pendiente, string $token, array &$revision): bool
    {
        if ($revision['vistos'] !== []) {
            return false;
        }

        ['resultados' => $resultados] = $this->cliente->buscarPagos($token, $pendiente->referenciaMercadoPago());
        $revision['consultas'][] = ['metodo' => 'search', 'encontrados' => count($resultados)];

        foreach ($resultados as $pagoMp) {
            if ($this->revisar($pendiente, $pagoMp, 'search', false, $revision)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Este pago de Mercado Pago es el de la mensualidad y está aprobado? Si
     * sí, lo aplica (mismo criterio que la verificación de G7).
     */
    private function revisar(Pago $pendiente, array $pagoMp, string $metodo, bool $loPidioQuienLlama, array &$revision): bool
    {
        $id = is_scalar($pagoMp['id'] ?? null) ? (string) $pagoMp['id'] : '';
        $status = is_string($pagoMp['status'] ?? null) ? $pagoMp['status'] : null;

        if ($id !== '' && isset($revision['vistos'][$id])) {
            return false;
        }

        $revision['vistos'][$id] = $status;

        if ($status !== 'approved') {
            if (($pagoMp['external_reference'] ?? null) === $pendiente->referenciaMercadoPago()) {
                $revision['estados'][] = $status;
            } elseif ($loPidioQuienLlama) {
                $revision['no_cuadra'] ??= 'otra external_reference';
            }

            return false;
        }

        $motivo = $this->aplicar->motivoNoCuadra($pendiente, $pagoMp);

        if ($motivo !== null) {
            $revision['no_cuadra'] ??= $motivo;

            return false;
        }

        if ($this->aplicar->ejecutar($pendiente, $pagoMp) !== AplicarPagoSuscripcionMercadoPagoAction::NO_APLICA) {
            $revision['aplicado'] = $metodo;

            return true;
        }

        $revision['no_cuadra'] ??= 'el pago ya no admite aplicarse';

        return false;
    }
}
