<?php

namespace App\Console\Commands;

use App\Services\Cliente\DepurarTelefonosClientesDirectos;
use Illuminate\Console\Command;

/**
 * Lista los teléfonos de clientes directos que cambiarían.
 * Solo escribe con --confirmar. La migración aplica la misma depuración
 * al crear el índice; este comando sirve para verla antes, en modo prueba.
 */
class DepurarTelefonosClientes extends Command
{
    protected $signature = 'clientes:depurar-telefonos {--confirmar : Aplica los cambios. Sin esta opción solo los lista.}';

    protected $description = 'Normaliza a 10 dígitos los teléfonos de clientes directos y deja en null los vacíos o duplicados.';

    public function handle(DepurarTelefonosClientesDirectos $depurar): int
    {
        $pendientes = $depurar->pendientes();

        if ($pendientes === []) {
            $this->info('No hay teléfonos de clientes directos que cambiar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Distribuidora', 'Nombre', 'Anterior', 'Nuevo', 'Motivo', 'Conserva'],
            array_map(fn (array $fila) => [
                $fila['id'],
                $fila['distribuidora_id'],
                $fila['nombre'],
                $fila['anterior'] ?? '—',
                $fila['nuevo'] ?? 'NULL',
                $fila['motivo'],
                $fila['conserva_id'] ?? '—',
            ], $pendientes),
        );

        $this->line(count($pendientes).' cambio(s).');

        if (! $this->option('confirmar')) {
            $this->comment('Modo prueba: no se escribió nada. Para aplicar: php artisan clientes:depurar-telefonos --confirmar');

            return self::SUCCESS;
        }

        $aplicados = $depurar->aplicar();
        $this->info('Se aplicaron '.$aplicados.' cambio(s). Quedaron en auditorias con el teléfono anterior.');

        return self::SUCCESS;
    }
}
