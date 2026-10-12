<?php

namespace App\Console\Commands;

use App\Services\Vale\AvisarValesPorVencerAction;
use App\Services\Vale\ResumenValesPorVencerAction;
use Illuminate\Console\Command;

/**
 * TG-242 (A13 / E16-02) — php artisan vales:avisar-por-vencer
 *
 * Lo corre un servicio cron de Railway una vez al día, igual que el respaldo
 * de la base (TG-235). Se puede correr a mano con --dias para probar sin
 * esperar a que un vale se acerque de verdad a su fecha.
 */
class AvisarValesPorVencer extends Command
{
    protected $signature = 'vales:avisar-por-vencer {--dias= : Con cuántos días de anticipación avisar (por omisión, el del .env)}';

    protected $description = 'Avisa a quien tiene un vale con saldo que está por vencer';

    public function handle(AvisarValesPorVencerAction $accion, ResumenValesPorVencerAction $resumen): int
    {
        $dias = $this->option('dias');

        $resultado = $accion->ejecutar($dias !== null ? (int) $dias : null);

        $this->info(sprintf(
            'Vales por vencer revisados: %d. Avisados: %d. Sin cuenta en la app: %d.',
            $resultado['revisados'],
            $resultado['avisados'],
            $resultado['sin_cuenta'],
        ));

        // TG-122: y un resumen al personal de cada distribuidora.
        $resumenes = $resumen->ejecutar($dias !== null ? (int) $dias : null);
        $this->info(sprintf(
            'Resumen al personal: %d distribuidoras, %d avisos.',
            $resumenes['distribuidoras'],
            $resumenes['avisos'],
        ));

        return self::SUCCESS;
    }
}
