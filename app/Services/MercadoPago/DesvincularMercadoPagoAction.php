<?php

namespace App\Services\MercadoPago;

use App\Models\ConfiguracionDistribuidora;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Facades\DB;

/**
 * TG-225 (G6) — Desconecta la cuenta de Mercado Pago de la distribuidora.
 *
 * Borra las credenciales guardadas. Mercado Pago no ofrece una API para que
 * la plataforma revoque el permiso: si la distribuidora quiere quitarlo
 * también del lado de MP, lo hace desde su cuenta de Mercado Pago.
 */
class DesvincularMercadoPagoAction
{
    public function __construct(private readonly RegistrarAuditoriaAction $auditoria)
    {
    }

    public function ejecutar(ConfiguracionDistribuidora $configuracion): void
    {
        DB::transaction(function () use ($configuracion) {
            $cuentaAnterior = $configuracion->mercado_pago_account_id;

            $configuracion->update([
                'mp_access_token'         => null,
                'mp_public_key'           => null,
                'mercado_pago_account_id' => null,
                'mp_conectado_at'         => null,
            ]);

            $this->auditoria->ejecutar(
                'mercado_pago.desconectado',
                'configuracion_distribuidora',
                $configuracion->id,
                ['cuenta_mercado_pago' => $cuentaAnterior],
                ['cuenta_mercado_pago' => null],
            );
        });
    }
}
