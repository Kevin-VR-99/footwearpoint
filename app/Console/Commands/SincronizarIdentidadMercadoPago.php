<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionDistribuidora;
use App\Services\MercadoPago\SincronizarIdentidadMercadoPagoAction;
use Illuminate\Console\Command;

/**
 * php artisan mercado-pago:sincronizar-identidad
 *
 * Completa el titular, correo y usuario de las cuentas de Mercado Pago
 * que ya estaban conectadas antes de guardar esos datos.
 */
class SincronizarIdentidadMercadoPago extends Command
{
    protected $signature = 'mercado-pago:sincronizar-identidad
        {--forzar : Vuelve a consultar aunque ya se haya guardado el titular}
        {--distribuidora= : Solo esta distribuidora}';

    protected $description = 'Consulta y guarda el nombre, correo y usuario de las cuentas de Mercado Pago ya conectadas';

    public function handle(SincronizarIdentidadMercadoPagoAction $accion): int
    {
        $consulta = ConfiguracionDistribuidora::withoutGlobalScopes()
            ->whereNotNull('mp_conectado_at')
            ->whereNotNull('mp_access_token')
            ->orderBy('id');

        if ($this->option('distribuidora') !== null && $this->option('distribuidora') !== '') {
            $consulta->where('distribuidora_id', (int) $this->option('distribuidora'));
        }

        if (! $this->option('forzar')) {
            $consulta->whereNull('mp_identidad_consultada_at');
        }

        $revisadas = 0;
        $actualizadas = 0;
        $fallidas = 0;

        foreach ($consulta->get() as $configuracion) {
            $revisadas++;

            if ($accion->ejecutar($configuracion, (bool) $this->option('forzar'))) {
                $actualizadas++;
            } else {
                $fallidas++;
            }
        }

        $this->info(sprintf(
            'Cuentas revisadas: %d. Actualizadas: %d. Sin cambios o con error: %d.',
            $revisadas,
            $actualizadas,
            $fallidas,
        ));

        return self::SUCCESS;
    }
}
