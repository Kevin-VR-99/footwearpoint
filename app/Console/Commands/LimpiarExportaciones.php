<?php

namespace App\Console\Commands;

use App\Models\Exportacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan exportaciones:limpiar — borra los archivos de exportaciones
 * vencidas (y los registros de más de 30 días). Cron opcional en Railway,
 * una vez al día (docs/railway-produccion.md, I).
 */
class LimpiarExportaciones extends Command
{
    protected $signature = 'exportaciones:limpiar';

    protected $description = 'Borra los archivos de exportaciones vencidas del disco privado';

    public function handle(): int
    {
        $borrados = 0;

        Exportacion::withoutGlobalScopes()
            ->whereNotNull('ruta')
            ->where('expira_at', '<', now())
            ->chunkById(200, function ($exportaciones) use (&$borrados) {
                foreach ($exportaciones as $e) {
                    try {
                        Storage::disk($e->disco)->delete($e->ruta);
                    } catch (Throwable $ex) {
                        report($ex);

                        continue;
                    }
                    $e->update(['ruta' => null]);
                    $borrados++;
                }
            });

        $viejas = Exportacion::withoutGlobalScopes()->whereNull('ruta')->where('created_at', '<', now()->subDays(30))->delete();

        $this->info("Archivos borrados: {$borrados}. Registros viejos borrados: {$viejas}.");

        return self::SUCCESS;
    }
}
