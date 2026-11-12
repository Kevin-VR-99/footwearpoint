<?php

use App\Services\Cliente\DepurarTelefonosClientesDirectos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teléfono único de clientes directos por distribuidora (S5-56).
 *
 * Primero corre la misma depuración que
 * `php artisan clientes:depurar-telefonos --confirmar`: normaliza a 10
 * dígitos, deja en null los vacíos y los duplicados de la misma
 * distribuidora, escribe la auditoría con el valor anterior y marca
 * recaptura en quien tiene cuenta. Después crea el índice único.
 *
 * En una base vacía no hay nada que cambiar y solo crea la columna y el
 * índice. Volver a ejecutarla no duplica el índice ni reescribe teléfonos
 * que ya quedaron bien. El comando sigue existiendo para listar los
 * cambios en modo prueba.
 *
 * El índice único permite varios null: null no choca.
 * down() quita el índice y la marca de recaptura. No restaura teléfonos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clientes_directos', 'telefono_por_recapturar')) {
            Schema::table('clientes_directos', function (Blueprint $tabla) {
                $tabla->boolean('telefono_por_recapturar')->default(false)->after('telefono');
            });
        }

        app(DepurarTelefonosClientesDirectos::class)->aplicar();

        $pendientes = app(DepurarTelefonosClientesDirectos::class)->pendientes();

        if ($pendientes !== []) {
            throw new RuntimeException(
                'La depuración de teléfonos no dejó la base lista para el índice único. '
                .'No se creó el índice. Revisa php artisan clientes:depurar-telefonos.'
            );
        }

        if (! $this->indiceExiste()) {
            Schema::table('clientes_directos', function (Blueprint $tabla) {
                $tabla->unique(['distribuidora_id', 'telefono'], 'uq_clientes_directos_telefono');
            });
        }
    }

    public function down(): void
    {
        if ($this->indiceExiste()) {
            Schema::table('clientes_directos', function (Blueprint $tabla) {
                $tabla->dropUnique('uq_clientes_directos_telefono');
            });
        }

        if (Schema::hasColumn('clientes_directos', 'telefono_por_recapturar')) {
            Schema::table('clientes_directos', function (Blueprint $tabla) {
                $tabla->dropColumn('telefono_por_recapturar');
            });
        }
    }

    private function indiceExiste(): bool
    {
        $indices = Schema::getIndexes('clientes_directos');

        foreach ($indices as $indice) {
            if (($indice['name'] ?? '') === 'uq_clientes_directos_telefono') {
                return true;
            }
        }

        return false;
    }
};
