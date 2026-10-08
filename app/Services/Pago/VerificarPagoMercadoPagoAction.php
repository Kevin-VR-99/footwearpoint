<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TG-226 (G7) — "¿Ya se pagó?" Se pregunta directo a Mercado Pago.
 *
 * La app lo llama cuando el cliente regresa de pagar, el personal desde el
 * detalle del pedido y la página de regreso de Mercado Pago. Lo que traiga
 * la URL o quien llama (un payment_id) solo dice DÓNDE preguntar: lo que
 * decide es lo que contesta la API de Mercado Pago con el token de la
 * distribuidora, y AplicarPagoMercadoPagoAction (el mismo que usará el aviso
 * de G9) revisa que sea de este pago antes de aplicarlo.
 *
 * Bugfix: la búsqueda por external_reference (/v1/payments/search) puede
 * tardar en mostrar un pago recién aprobado, y así un pago aprobado se
 * quedaba "pendiente". Ahora se pregunta, en este orden:
 *   1. GET /v1/payments/{payment_id}, si quien llama trae el payment_id;
 *   2. las órdenes de la preferencia (GET /merchant_orders/search) y cada
 *      pago que traigan, con GET /v1/payments/{id};
 *   3. la búsqueda por external_reference, solo si lo anterior no encontró
 *      ningún pago.
 * Cada vez que no se aplica nada queda un Log::warning con lo que contestó
 * Mercado Pago (sin tokens ni datos de quien pagó).
 */
class VerificarPagoMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const PENDIENTE = 'pendiente';

    public const RECHAZADO = 'rechazado';

    public const VENCIDO = 'vencido';

    /** Mercado Pago tiene un pago que no corresponde a este (no se aplica). */
    public const NO_CUADRA = 'no_cuadra';

    /** Pagos de una preferencia que se consultan uno por uno, como máximo. */
    private const MAX_PAGOS_POR_ORDEN = 5;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoDistribuidora $tokens,
        private readonly AplicarPagoMercadoPagoAction $aplicar,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @param  string|null  $pagoMpId  El payment_id de Mercado Pago, si quien llama lo tiene.
     * @param  string  $origen  api, panel o retorno (solo para el log).
     * @return string Uno de APLICADO, PENDIENTE, RECHAZADO, VENCIDO o NO_CUADRA.
     *
     * @throws MercadoPagoException si no hay nada que verificar o MP no responde.
     */
    public function ejecutar(Pedido $pedido, ?string $pagoMpId = null, string $origen = 'api'): string
    {
        $pagosMp = Pago::query()
            ->where('pedido_id', $pedido->id)
            ->where('metodo', 'mercado_pago')
            ->orderByDesc('id')
            ->get();

        $pendiente = $pagosMp->first(fn (Pago $p) => $p->estado === 'pendiente' && $p->preferencia_externa !== null);

        if ($pendiente === null) {
            // Ya se había confirmado (por ejemplo, el cliente tocó dos veces).
            if ($pagosMp->isNotEmpty() && $pagosMp->first()->estado === 'aplicado') {
                return self::APLICADO;
            }

            throw MercadoPagoException::con(MercadoPagoException::SIN_PAGO_POR_CONFIRMAR);
        }

        return $this->verificarPago($pendiente, $pagoMpId, $origen);
    }

    /**
     * Verifica un pago pendiente en particular (la página de regreso ya sabe
     * cuál es por la external_reference).
     */
    public function verificarPago(Pago $pendiente, ?string $pagoMpId = null, string $origen = 'api'): string
    {
        ['token' => $token] = $this->tokens->para((int) $pendiente->distribuidora_id);

        $revision = [
            'vistos'     => [],   // id de MP => status
            'estados'    => [],   // status de los pagos que SÍ son de este pago
            'no_cuadra'  => null, // primer motivo por el que algo no corresponde
            'consultas'  => [],   // para el log
            'aplicado'   => null, // método con el que se aplicó
        ];

        if ($this->porId($pendiente, $token, $pagoMpId, $revision)
            || $this->porOrdenes($pendiente, $token, $revision)
            || $this->porBusqueda($pendiente, $token, $revision)) {
            Log::info('Mercado Pago: pago confirmado y aplicado.', [
                'pago_id' => $pendiente->id,
                'origen'  => $origen,
                'metodo'  => $revision['aplicado'],
            ]);

            return self::APLICADO;
        }

        $resultado = match (true) {
            // Si algo no cuadra no se vence: el personal tiene que revisarlo.
            $revision['no_cuadra'] !== null => self::NO_CUADRA,
            $pendiente->venceMercadoPagoAt()->isPast() => self::VENCIDO,
            collect($revision['estados'])->contains(fn ($s) => in_array($s, ['rejected', 'cancelled'], true)) => self::RECHAZADO,
            default => self::PENDIENTE,
        };

        if ($resultado === self::VENCIDO) {
            $this->marcarVencido($pendiente);
        }

        Log::warning('Mercado Pago: el pago no se aplicó al verificar.', [
            'pago_id'   => $pendiente->id,
            'pedido_id' => $pendiente->pedido_id,
            'origen'    => $origen,
            'resultado' => $resultado,
            'motivo'    => $revision['no_cuadra'],
            'consultas' => $revision['consultas'],
        ]);

        return $resultado;
    }

    /** 1. El payment_id que trae quien llama. */
    private function porId(Pago $pendiente, string $token, ?string $pagoMpId, array &$revision): bool
    {
        if ($pagoMpId === null || $pagoMpId === '') {
            return false;
        }

        $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $pagoMpId);

        $revision['consultas'][] = [
            'metodo'     => 'payment_id',
            'payment_id' => $pagoMpId,
            'encontrado' => $pagoMp !== null,
            'status'     => $pagoMp['status'] ?? null,
        ];

        if ($pagoMp === null) {
            // Con el token de la distribuidora no existe: no es un pago suyo.
            $revision['no_cuadra'] ??= 'payment_id no encontrado con el token de la distribuidora';

            return false;
        }

        return $this->revisar($pendiente, $pagoMp, 'payment_id', true, $revision);
    }

    /** 2. Los pagos de las órdenes de la preferencia. */
    private function porOrdenes(Pago $pendiente, string $token, array &$revision): bool
    {
        ['ordenes' => $ordenes, 'pagos' => $ids] = $this->cliente->pagosDeLaPreferencia($token, (string) $pendiente->preferencia_externa);

        $ids = array_slice(array_values(array_filter($ids, fn ($id) => ! isset($revision['vistos'][$id]))), 0, self::MAX_PAGOS_POR_ORDEN);
        $estados = [];

        foreach ($ids as $id) {
            $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $id);
            $estados[$id] = $pagoMp['status'] ?? 'no_encontrado';

            if ($pagoMp !== null && $this->revisar($pendiente, $pagoMp, 'merchant_order', false, $revision)) {
                return true;
            }
        }

        $revision['consultas'][] = [
            'metodo'  => 'merchant_order',
            'ordenes' => $ordenes,
            'pagos'   => $estados,
        ];

        return false;
    }

    /** 3. Último recurso: la búsqueda por external_reference. */
    private function porBusqueda(Pago $pendiente, string $token, array &$revision): bool
    {
        if ($revision['vistos'] !== []) {
            return false;
        }

        ['resultados' => $resultados, 'total' => $total] = $this->cliente->buscarPagos($token, $pendiente->referenciaMercadoPago());

        $revision['consultas'][] = [
            'metodo'     => 'busqueda',
            'total'      => $total,
            'resultados' => count($resultados),
            'estados'    => array_map(fn (array $p) => $p['status'] ?? null, $resultados),
        ];

        foreach ($resultados as $pagoMp) {
            if ($this->revisar($pendiente, $pagoMp, 'busqueda', false, $revision)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Este pago de Mercado Pago es de este pago y está aprobado? Si sí, lo
     * aplica. Anota en $revision lo que encontró.
     */
    private function revisar(Pago $pendiente, array $pagoMp, string $metodo, bool $loPidioQuienLlama, array &$revision): bool
    {
        $id = is_scalar($pagoMp['id'] ?? null) ? (string) $pagoMp['id'] : '';
        $status = is_string($pagoMp['status'] ?? null) ? $pagoMp['status'] : null;

        if ($id !== '' && isset($revision['vistos'][$id])) {
            return false;
        }

        $revision['vistos'][$id] = $status;
        $motivo = $this->aplicar->motivoNoCuadra($pendiente, $pagoMp);

        if ($status !== 'approved') {
            if (($pagoMp['external_reference'] ?? null) === $pendiente->referenciaMercadoPago()) {
                $revision['estados'][] = $status;
            } elseif ($loPidioQuienLlama) {
                $revision['no_cuadra'] ??= 'otra external_reference';
            }

            return false;
        }

        if ($motivo !== null) {
            $revision['no_cuadra'] ??= $motivo;
            $revision['consultas'][] = ['metodo' => $metodo, 'payment_id' => $id, 'rechazado_por' => $motivo];

            return false;
        }

        if ($this->aplicar->ejecutar($pendiente, $pagoMp) !== AplicarPagoMercadoPagoAction::NO_APLICA) {
            $revision['aplicado'] = $metodo;

            return true;
        }

        // Cuadraba pero no se pudo aplicar (por ejemplo, ya estaba revertido).
        $revision['no_cuadra'] ??= 'el pago ya no admite aplicarse';

        return false;
    }

    private function marcarVencido(Pago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $actual = Pago::query()->whereKey($pago->id)->lockForUpdate()->first();

            if ($actual?->estado !== 'pendiente') {
                return;
            }

            $actual->update(['estado' => 'fallido']);

            $this->auditoria->ejecutar(
                'pago.mercado_pago.vencido',
                'pago',
                $actual->id,
                ['estado' => 'pendiente'],
                ['estado' => 'fallido', 'pedido_id' => $actual->pedido_id],
            );
        });
    }
}
