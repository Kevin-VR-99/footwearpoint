<?php

namespace App\Console\Commands;

use App\Services\Pedido\VencerRecoleccionAction;
use Illuminate\Console\Command;

/**
 * TG-125 (E13-02) — php artisan pedidos:vencer-recoleccion
 *
 * Lo corre un servicio cron de Railway una vez al día (ver
 * docs/vencer-recoleccion.md). Con --dry-run solo dice qué pedidos vencería.
 */
class VencerRecoleccion extends Command
{
    protected $signature = 'pedidos:vencer-recoleccion {--dry-run : Solo lista los pedidos que vencería, sin cambiar nada}';

    protected $description = 'Marca como «vencido recolección» los pedidos listos que no se recogieron a tiempo';

    public function handle(VencerRecoleccionAction $accion): int
    {
        $simular = (bool) $this->option('dry-run');
        $resultado = $accion->ejecutar($simular);

        if ($simular) {
            $this->info(sprintf('Simulación: %d pedidos vencerían.', $resultado['revisados']));
        } else {
            $this->info(sprintf(
                'Pedidos con plazo vencido: %d. Marcados como vencidos: %d.',
                $resultado['revisados'],
                $resultado['vencidos'],
            ));
        }

        foreach ($resultado['folios'] as $folio) {
            $this->line('  '.$folio);
        }

        return self::SUCCESS;
    }
}
