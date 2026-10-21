<?php

namespace App\Console\Commands;

use App\Services\MercadoPago\RenovarTokensMercadoPagoAction;
use Illuminate\Console\Command;

/**
 * php artisan mercado-pago:renovar-tokens
 *
 * Lo corre un cron semanal de Railway (ver docs/railway-produccion.md).
 * Renueva los tokens que vencen en los próximos 30 días y, si no se puede,
 * avisa a la distribuidora para que vuelva a conectar su cuenta.
 */
class RenovarTokensMercadoPago extends Command
{
    protected $signature = 'mercado-pago:renovar-tokens
        {--dry-run : Solo cuenta las conexiones por vencer, sin cambiar nada}
        {--sin-correo : Avisa solo en la campana del panel, sin correo}';

    protected $description = 'Renueva los tokens OAuth de Mercado Pago de las distribuidoras antes de que venzan';

    public function handle(RenovarTokensMercadoPagoAction $accion): int
    {
        $r = $accion->ejecutar((bool) $this->option('dry-run'), ! $this->option('sin-correo'));

        $this->info(sprintf(
            'Conexiones por vencer: %d. Renovadas: %d. Fallidas: %d. Avisos enviados: %d.',
            $r['revisadas'], $r['renovadas'], $r['fallidas'], $r['avisadas'],
        ));

        return self::SUCCESS;
    }
}
