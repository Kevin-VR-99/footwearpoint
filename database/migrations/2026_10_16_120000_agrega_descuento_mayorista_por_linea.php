<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El descuento de mayoreo puede ser distinto en cada línea activa.
 * Null significa que esa línea sigue usando el descuento general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidora_linea', function (Blueprint $tabla) {
            $tabla->decimal('descuento_mayorista_pct', 5, 2)
                ->nullable()
                ->after('es_extra');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidora_linea', function (Blueprint $tabla) {
            $tabla->dropColumn('descuento_mayorista_pct');
        });
    }
};
