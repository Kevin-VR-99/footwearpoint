<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-220 (E2-08) — Qué plan eligió quien pidió su alta.
 *
 * El formulario público deja elegir plan, pero la suscripción no se abre
 * hasta que el admin general aprueba. Mientras tanto la elección se guarda
 * aquí, y AprobarDistribuidoraAction la usa; si está vacía, sigue usando el
 * plan por defecto, como antes.
 *
 * Opcional a propósito: las distribuidoras que ya existen y las que da de
 * alta el admin general no eligen plan en ningún formulario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('plan_solicitado_id')->nullable()->after('estado');

            $tabla->foreign('plan_solicitado_id', 'fk_distribuidoras_plan_solicitado')
                ->references('id')->on('planes_suscripcion')
                ->onUpdate('restrict')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_distribuidoras_plan_solicitado');
            $tabla->dropColumn('plan_solicitado_id');
        });
    }
};
