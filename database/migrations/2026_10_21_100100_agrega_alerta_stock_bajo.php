<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerta de stock bajo.
 * - configuraciones_distribuidora.stock_minimo_general: mínimo para todas las
 *   variantes (0 = sin alerta general).
 * - stock_local.stock_minimo (ya existía) queda como mínimo propio de la
 *   variante; 0 = usa el general.
 * - stock_local.alerta_stock_bajo_at: cuándo se avisó; se limpia al reponer
 *   para no repetir el aviso mientras siga bajo.
 * Idempotente: se puede correr dos veces sin romper.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('configuraciones_distribuidora', 'stock_minimo_general')) {
            Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
                $tabla->unsignedInteger('stock_minimo_general')->default(0);
            });
        }

        if (! Schema::hasColumn('stock_local', 'alerta_stock_bajo_at')) {
            Schema::table('stock_local', function (Blueprint $tabla) {
                $tabla->timestamp('alerta_stock_bajo_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_local', 'alerta_stock_bajo_at')) {
            Schema::table('stock_local', fn (Blueprint $tabla) => $tabla->dropColumn('alerta_stock_bajo_at'));
        }

        if (Schema::hasColumn('configuraciones_distribuidora', 'stock_minimo_general')) {
            Schema::table('configuraciones_distribuidora', fn (Blueprint $tabla) => $tabla->dropColumn('stock_minimo_general'));
        }
    }
};
