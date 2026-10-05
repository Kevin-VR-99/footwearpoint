<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, A12) — Aviso de vale por vencer.
 *
 * Guarda cuándo se le avisó al dueño del vale que está por vencer. Así la
 * tarea que manda los avisos no le manda el mismo aviso cada vez que corre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $tabla) {
            $tabla->dateTime('aviso_vencimiento_enviado_at')->nullable()->after('fecha_vencimiento');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $tabla) {
            $tabla->dropColumn('aviso_vencimiento_enviado_at');
        });
    }
};
