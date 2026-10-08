<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use Illuminate\Support\Facades\DB;

/**
 * TG-226 (G7) — "¿Ya se pagó?" Se pregunta directo a Mercado Pago.
 *
 * La app lo llama cuando el cliente regresa de pagar, y el personal desde el
 * detalle del pedido. Nunca se usa lo que trae la URL de regreso: se buscan
 * los pagos de Mercado Pago por external_reference con el token de la
 * distribuidora y, si hay uno aprobado, lo aplica AplicarPagoMercadoPagoAction
 * (el mismo que usará el aviso de G9).
 */
class VerificarPagoMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const PENDIENTE = 'pendiente';

    public const RECHAZADO = 'rechazado';

    public const VENCIDO = 'vencido';

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoDistribuidora $tokens,
        private readonly AplicarPagoMercadoPagoAction $aplicar,
        private readonly RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /**
     * @return string Uno de APLICADO, PENDIENTE, RECHAZADO o VENCIDO.
     *
     * @throws MercadoPagoException si no hay nada que verificar o MP no responde.
     */
    public function ejecutar(Pedido $pedido): string
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

        ['token' => $token] = $this->tokens->para((int) $pedido->distribuidora_id);

        $encontrados = $this->cliente->buscarPagos($token, $pendiente->referenciaMercadoPago());

        foreach ($encontrados as $pagoMp) {
            if (($pagoMp['status'] ?? null) !== 'approved') {
                continue;
            }

            $resultado = $this->aplicar->ejecutar($pendiente, $pagoMp);

            if ($resultado !== AplicarPagoMercadoPagoAction::NO_APLICA) {
                return self::APLICADO;
            }
        }

        if ($pendiente->venceMercadoPagoAt()->isPast()) {
            $this->marcarVencido($pendiente);

            return self::VENCIDO;
        }

        $rechazado = collect($encontrados)->contains(fn (array $p) => in_array($p['status'] ?? null, ['rejected', 'cancelled'], true));

        return $rechazado ? self::RECHAZADO : self::PENDIENTE;
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
