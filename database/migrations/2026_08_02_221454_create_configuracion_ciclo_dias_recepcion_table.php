<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('configuracion_ciclo_dias_recepcion', function (Blueprint $table) {
            $table->unsignedBigInteger('configuracion_ciclo_id');
            $table->unsignedTinyInteger('dia_semana');

            $table->primary(['configuracion_ciclo_id', 'dia_semana']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_ciclo_dias_recepcion');
    }
};
