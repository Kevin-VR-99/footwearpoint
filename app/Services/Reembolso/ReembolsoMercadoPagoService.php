<?php

namespace App\Services\Reembolso;

use App\Exceptions\OperacionInvalidaException;
use App\Jobs\ProcesarReembolsoMercadoPago;
use App\Models\Pago;
use App\Models\ReembolsoMercadoPago;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\Pago\TokenMercadoPagoDistribuidora;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reembolsos automáticos en Mercado Pago.
 *
 * Cuando se cancela una venta o se devuelve un anticipo que se cobró con
 * Mercado Pago, en lugar de dejar el reembolso "manual" se pide a la API de
 * Mercado Pago (POST /v1/payments/{id}/refunds) con el token de la cuenta de
 * la distribuidora:
 *
 *   1. solicitar(): deja el renglón 'pendiente' con su llave de idempotencia
 *      y manda el trabajo a la cola cuando la transacción se confirma.
 *   2. procesar() (desde la cola): llama a Mercado Pago. Aprobado, en
 *      proceso, rechazado o falla temporal (la cola reintenta).
 *   3. reintentar(): el personal con permiso lo vuelve a mandar si falló.
 *   4. desdeAviso(): el webhook de Mercado Pago confirma el reembolso.
 *
 * Solo para cobros con Mercado Pago; efectivo, tarjeta, etc. no pasan por
 * aquí. Todo corre con el scope de la distribuidora del cobro.
 */
class ReembolsoMercadoPagoService
{
    /** Estados de reembolso de Mercado Pago que todavía pueden cambiar. */
    private const EN_PROCESO_MP = ['in_process', 'pending', 'authorized'];

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoDistribuidora $tokens,
        private readonly RegistrarAuditoriaAction $auditoria,
        private readonly PermisoModuloService $permisos,
    ) {
    }

    /** true si ese cobro se puede devolver automáticamente en Mercado Pago. */
    public static function esReembolsable(Pago $cobro): bool
    {
        return $cobro->metodo === 'mercado_pago'
            && $cobro->direccion === 'entrada'
            && $cobro->estado === 'aplicado'
            && is_string($cobro->referencia_externa)
            && preg_match('/^\d{1,20}$/', $cobro->referencia_externa) === 1;
    }

    /** Lo que todavía se puede devolver de ese cobro (descontando lo ya pedido). */
    public function disponible(Pago $cobro): float
    {
        $pedido = (float) ReembolsoMercadoPago::withoutGlobalScopes()
            ->where('distribuidora_id', $cobro->distribuidora_id)
            ->where('pago_id', $cobro->id)
            ->sum('monto');

        return max(0, round((float) $cobro->monto - $pedido, 2));
    }

    /**
     * Deja el reembolso en cola. Llamar dentro de la transacción que asienta
     * la devolución: el trabajo sale solo si la transacción se confirma.
     */
    public function solicitar(Pago $cobro, float $monto, ?string $motivo, ?int $staffId, ?int $pagoReembolsoId = null): ReembolsoMercadoPago
    {
        if (! self::esReembolsable($cobro)) {
            throw new OperacionInvalidaException('Ese cobro no se puede devolver automáticamente en Mercado Pago.', 422);
        }

        $monto = round($monto, 2);
        $disponible = $this->disponible($cobro);

        if ($monto <= 0 || $monto > $disponible) {
            throw new OperacionInvalidaException(
                'No se puede devolver $'.number_format($monto, 2).' de ese cobro de Mercado Pago (disponible: $'.number_format($disponible, 2).').',
                422
            );
        }

        $reembolso = Tenant::forzar((int) $cobro->distribuidora_id, function () use ($cobro, $monto, $disponible, $motivo, $staffId, $pagoReembolsoId) {
            $reembolso = ReembolsoMercadoPago::create([
                'distribuidora_id'        => (int) $cobro->distribuidora_id,
                'pago_id'                 => (int) $cobro->id,
                'pago_reembolso_id'       => $pagoReembolsoId,
                'venta_directa_id'        => $cobro->venta_directa_id,
                'pedido_id'               => $cobro->pedido_id,
                'mp_pago_id'              => (string) $cobro->referencia_externa,
                'monto'                   => $monto,
                // Parcial: si no se devuelve todo el cobro o ya hubo otra devolución.
                'parcial'                 => $monto < round((float) $cobro->monto, 2) || $disponible < round((float) $cobro->monto, 2),
                'estado'                  => ReembolsoMercadoPago::PENDIENTE,
                'idempotency_key'         => (string) Str::uuid(),
                'motivo'                  => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
                'solicitado_por_staff_id' => $staffId,
            ]);

            $this->auditoria->ejecutar('mercado_pago.reembolso_solicitado', 'reembolso_mercado_pago', (int) $reembolso->id, null, [
                'pago_id'          => (int) $cobro->id,
                'folio'            => $cobro->folio,
                'venta_directa_id' => $cobro->venta_directa_id,
                'pedido_id'        => $cobro->pedido_id,
                'monto'            => $monto,
                'parcial'          => (bool) $reembolso->parcial,
            ]);

            return $reembolso;
        });

        $this->encolar((int) $reembolso->id);

        return $reembolso;
    }

    /**
     * Llama a Mercado Pago (desde la cola).
     *
     * @throws ReembolsoTemporalException si conviene reintentar más tarde.
     */
    public function procesar(int $reembolsoId): void
    {
        $distribuidoraId = ReembolsoMercadoPago::withoutGlobalScopes()->whereKey($reembolsoId)->value('distribuidora_id');

        if ($distribuidoraId === null) {
            return;
        }

        Tenant::forzar((int) $distribuidoraId, function () use ($reembolsoId, $distribuidoraId) {
            $reembolso = DB::transaction(function () use ($reembolsoId) {
                $r = ReembolsoMercadoPago::query()->whereKey($reembolsoId)->lockForUpdate()->first();

                // Ya aprobado o fallido (el personal decide): nada que hacer.
                if ($r === null || in_array($r->estado, [ReembolsoMercadoPago::APROBADO, ReembolsoMercadoPago::FALLIDO], true)) {
                    return null;
                }

                $r->forceFill(['estado' => ReembolsoMercadoPago::PROCESANDO, 'intentos' => (int) $r->intentos + 1])->save();

                return $r;
            });

            if ($reembolso === null) {
                return;
            }

            try {
                ['token' => $token] = $this->tokens->para((int) $distribuidoraId);
            } catch (MercadoPagoException $e) {
                // Sin cuenta conectada o token vencido: hace falta que la
                // distribuidora reconecte; reintentar solo no sirve.
                $this->fallar($reembolso, $e->getMessage());

                return;
            }

            // Total: sin monto. Parcial (o el resto de un cobro ya devuelto en parte): con monto.
            $monto = $reembolso->parcial ? (float) $reembolso->monto : null;

            try {
                $resultado = $this->cliente->reembolsarPago($token, $reembolso->mp_pago_id, $monto, (string) $reembolso->idempotency_key);
            } catch (MercadoPagoException $e) {
                $this->anotarErrorTemporal($reembolso, $e->getMessage());

                throw new ReembolsoTemporalException($e->getMessage());
            }

            if (! $resultado['ok']) {
                $http = (int) $resultado['http'];
                $error = (string) $resultado['error'];

                if ($http >= 500 || $http === 429) {
                    $this->anotarErrorTemporal($reembolso, $error);

                    throw new ReembolsoTemporalException('Mercado Pago no pudo procesar el reembolso por ahora ('.$error.').');
                }

                $this->fallar($reembolso, 'Mercado Pago rechazó el reembolso ('.$error.').');

                return;
            }

            $this->aplicarRespuesta($reembolso, $resultado['datos']);
        });
    }

    /** El personal vuelve a mandar un reembolso fallido. */
    public function reintentar(int $reembolsoId, ?Usuario $usuario): ReembolsoMercadoPago
    {
        // Con el scope del tenant: el de otra distribuidora no existe.
        $reembolso = ReembolsoMercadoPago::query()->whereKey($reembolsoId)->first();

        if ($reembolso === null) {
            throw new OperacionInvalidaException('El reembolso no existe.', 404);
        }

        $this->permisos->exigir($usuario, $reembolso->venta_directa_id !== null
            ? PermisoModuloService::VENTAS_CANCELAR
            : PermisoModuloService::PAGOS_REGISTRAR);

        if (! $reembolso->sePuedeReintentar()) {
            throw new OperacionInvalidaException('Solo se puede reintentar un reembolso que falló.', 409);
        }

        $antes = ['estado' => $reembolso->estado, 'error' => $reembolso->ultimo_error];

        // Si Mercado Pago lo rechazó (4xx), seguro no se creó ningún
        // reembolso: llave nueva para que no conteste lo mismo de su caché.
        // Si fue por falta de respuesta, se conserva: así nunca se duplica.
        $cambios = ['estado' => ReembolsoMercadoPago::PENDIENTE, 'ultimo_error' => null];
        if ($reembolso->mp_reembolso_id === null && str_contains((string) $reembolso->ultimo_error, 'HTTP 4')) {
            $cambios['idempotency_key'] = (string) Str::uuid();
        }

        $reembolso->forceFill($cambios)->save();

        $this->auditoria->ejecutar('mercado_pago.reembolso_reintentado', 'reembolso_mercado_pago', (int) $reembolso->id, $antes, ['estado' => ReembolsoMercadoPago::PENDIENTE]);

        $this->encolar((int) $reembolso->id);

        return $reembolso->fresh();
    }

    /** Lo usa el trabajo cuando se acaban los intentos. */
    public function marcarFallido(int $reembolsoId, string $error): void
    {
        $distribuidoraId = ReembolsoMercadoPago::withoutGlobalScopes()->whereKey($reembolsoId)->value('distribuidora_id');

        if ($distribuidoraId === null) {
            return;
        }

        Tenant::forzar((int) $distribuidoraId, function () use ($reembolsoId, $error) {
            $reembolso = ReembolsoMercadoPago::query()->whereKey($reembolsoId)->first();

            if ($reembolso !== null && $reembolso->estado !== ReembolsoMercadoPago::APROBADO) {
                $this->fallar($reembolso, $error);
            }
        });
    }

    /**
     * El aviso (webhook) de Mercado Pago reporta el pago como devuelto:
     * confirma los reembolsos en proceso de ese cobro. Regresa cuántos marcó.
     */
    public function desdeAviso(Pago $cobro, array $pagoMp): int
    {
        return Tenant::forzar((int) $cobro->distribuidora_id, function () use ($cobro, $pagoMp) {
            $reembolsosMp = collect(is_array($pagoMp['refunds'] ?? null) ? $pagoMp['refunds'] : [])->filter(fn ($r) => is_array($r));
            $marcados = 0;

            $pendientes = ReembolsoMercadoPago::query()
                ->where('pago_id', $cobro->id)
                ->whereIn('estado', [ReembolsoMercadoPago::PENDIENTE, ReembolsoMercadoPago::PROCESANDO])
                ->get();

            foreach ($pendientes as $reembolso) {
                $delMp = $reembolso->mp_reembolso_id !== null
                    ? $reembolsosMp->first(fn ($r) => (string) ($r['id'] ?? '') === $reembolso->mp_reembolso_id)
                    : null;

                // Sin id todavía: solo si el pago completo quedó devuelto.
                if ($delMp === null && ! ($reembolso->mp_reembolso_id === null && ($pagoMp['status'] ?? null) === 'refunded')) {
                    continue;
                }

                $this->aprobar($reembolso, $delMp ?? ['status' => 'approved'], 'aviso');
                $marcados++;
            }

            return $marcados;
        });
    }

    private function aplicarRespuesta(ReembolsoMercadoPago $reembolso, array $datos): void
    {
        $status = is_string($datos['status'] ?? null) ? $datos['status'] : '';
        $id = $datos['id'] ?? null;
        $idMp = is_scalar($id) && preg_match('/^\d{1,20}$/', (string) $id) === 1 ? (string) $id : null;

        if ($status === 'approved') {
            $this->aprobar($reembolso, $datos, 'api');

            return;
        }

        if (in_array($status, self::EN_PROCESO_MP, true)) {
            // Lo confirmará el aviso de Mercado Pago.
            $reembolso->forceFill(['estado' => ReembolsoMercadoPago::PROCESANDO, 'mp_reembolso_id' => $idMp, 'mp_estado' => $status, 'ultimo_error' => null])->save();

            return;
        }

        $reembolso->forceFill(['mp_reembolso_id' => $idMp, 'mp_estado' => $status !== '' ? $status : null])->save();
        $this->fallar($reembolso, 'Mercado Pago respondió el reembolso como «'.($status !== '' ? $status : 'sin estado').'».');
    }

    private function aprobar(ReembolsoMercadoPago $reembolso, array $datos, string $origen): void
    {
        $id = $datos['id'] ?? null;
        $idMp = is_scalar($id) && preg_match('/^\d{1,20}$/', (string) $id) === 1 ? (string) $id : $reembolso->mp_reembolso_id;

        $reembolso->forceFill([
            'estado'          => ReembolsoMercadoPago::APROBADO,
            'mp_reembolso_id' => $idMp,
            'mp_estado'       => 'approved',
            'ultimo_error'    => null,
            'aprobado_at'     => now(),
        ])->save();

        $this->auditoria->ejecutar('mercado_pago.reembolso_aprobado', 'reembolso_mercado_pago', (int) $reembolso->id, null, [
            'pago_id'         => (int) $reembolso->pago_id,
            'monto'           => (float) $reembolso->monto,
            'mp_reembolso_id' => $idMp,
            'origen'          => $origen,
        ]);
    }

    private function fallar(ReembolsoMercadoPago $reembolso, string $error): void
    {
        $reembolso->forceFill(['estado' => ReembolsoMercadoPago::FALLIDO, 'ultimo_error' => mb_substr($error, 0, 500)])->save();

        $this->auditoria->ejecutar('mercado_pago.reembolso_fallido', 'reembolso_mercado_pago', (int) $reembolso->id, null, [
            'pago_id'  => (int) $reembolso->pago_id,
            'monto'    => (float) $reembolso->monto,
            'intentos' => (int) $reembolso->intentos,
            'error'    => mb_substr($error, 0, 300),
        ]);
    }

    private function anotarErrorTemporal(ReembolsoMercadoPago $reembolso, string $error): void
    {
        $reembolso->forceFill(['estado' => ReembolsoMercadoPago::PENDIENTE, 'ultimo_error' => mb_substr($error, 0, 500)])->save();
    }

    private function encolar(int $reembolsoId): void
    {
        // Solo cuando la transacción de la cancelación se confirma.
        DB::afterCommit(fn () => ProcesarReembolsoMercadoPago::dispatch($reembolsoId));
    }
}
