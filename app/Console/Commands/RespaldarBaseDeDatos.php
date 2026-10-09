<?php

namespace App\Console\Commands;

use App\Services\Respaldo\RespaldarBaseDeDatosAction;
use App\Services\Respaldo\RespaldoException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TG-235 (G17) — php artisan respaldo:base-de-datos
 *
 * Lo corre un servicio cron de Railway una vez al día (ver
 * docs/respaldos.md). Termina con error si el respaldo no quedó guardado,
 * para que Railway marque la ejecución como fallida.
 */
class RespaldarBaseDeDatos extends Command
{
    protected $signature = 'respaldo:base-de-datos';

    protected $description = 'Respalda la base de datos (mysqldump + gzip) en el bucket privado de respaldos';

    public function handle(RespaldarBaseDeDatosAction $accion): int
    {
        try {
            $resultado = $accion->ejecutar();
        } catch (RespaldoException $e) {
            Log::error('Respaldo de la base de datos fallido.', ['motivo' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Respaldo guardado: %s (%s KB, %s s). Respaldos viejos borrados: %d.',
            $resultado['archivo'],
            number_format($resultado['bytes'] / 1024, 1),
            $resultado['segundos'],
            count($resultado['borrados'])
        ));

        return self::SUCCESS;
    }
}
