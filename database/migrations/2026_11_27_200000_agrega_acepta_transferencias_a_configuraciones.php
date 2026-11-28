<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptor «Aceptar transferencias» de la distribuidora. Encendido por
 * omisión, así que quien ya tiene datos bancarios sigue igual. Apagarlo no
 * borra banco, CLABE ni titular. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->boolean('acepta_transferencias')->default(true)->after('titular_cuenta');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn('acepta_transferencias');
        });
    }
};