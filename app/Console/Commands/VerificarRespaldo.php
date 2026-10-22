<?php

namespace App\Console\Commands;

use App\Services\Respaldo\RespaldoException;
use App\Services\Respaldo\VerificarRespaldoAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan respaldo:verificar — simulacro de restauración del respaldo más
 * reciente en una base aparte (por defecto footwearpoint_restaurada), compara
 * filas por tabla y la borra. Cron mensual opcional en Railway (docs/respaldos.md).
 */
class VerificarRespaldo extends Command
{
    protected $signature = 'respaldo:verificar
        {--base= : Base aparte donde se restaura (no puede ser DB_DATABASE)}
        {--reemplazar : Si la base de verificación ya existe, borrarla primero}
        {--conservar : No borrar la base de verificación al terminar (para revisarla a mano)}';

    protected $description = 'Restaura el respaldo más reciente en una base aparte, compara filas por tabla y la borra';

    public function handle(VerificarRespaldoAction $accion): int
    {
        try {
            $r = $accion->ejecutar($this->option('base') ?: null, (bool) $this->option('reemplazar'), (bool) $this->option('conservar'));
        } catch (RespaldoException $e) {
            Log::error('Simulacro de restauración fallido.', ['motivo' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            // Sin el mensaje crudo: podría traer el host o el usuario de la base.
            Log::error('Simulacro de restauración fallido.', ['error' => class_basename($e)]);
            $this->error('El simulacro falló ('.class_basename($e).'). Revisa permisos del usuario de MySQL (CREATE/DROP DATABASE) y el log.');

            return self::FAILURE;
        }

        $this->info("Respaldo: {$r['archivo']} → base {$r['base']} ({$r['segundos']} s).");
        $this->table(['Tabla', 'Principal', 'Respaldo'], collect($r['tablas'])
            ->map(fn ($c, $t) => [$t, $c['principal'], $c['restaurada'] ?? 'FALTA'])->values()->all());

        foreach ($r['avisos'] as $aviso) {
            $this->warn('Aviso: '.$aviso);
        }

        if (! $r['ok']) {
            $this->error($r['faltantes'] !== []
                ? 'Faltan tablas en el respaldo: '.implode(', ', $r['faltantes']).'.'
                : 'El respaldo se restauró vacío.');

            return self::FAILURE;
        }

        $this->info('Simulacro correcto: '.count($r['tablas']).' tablas restauradas.'.($this->option('conservar') ? '' : ' La base de verificación se borró.'));

        return self::SUCCESS;
    }
}
