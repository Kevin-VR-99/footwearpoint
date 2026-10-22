<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de estados de cada solicitud de cambio (la línea de tiempo que
 * ve el cliente en la app). Aditiva: las solicitudes de antes no tienen
 * renglones; la API arma su primer paso con fecha_solicitud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_cambio_historial', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('solicitud_cambio_id');
            $tabla->string('estado_anterior', 30)->nullable();
            $tabla->string('estado_nuevo', 30);
            $tabla->string('nota', 500)->nullable();
            $tabla->unsignedBigInteger('staff_id')->nullable();
            $tabla->timestamps();

            $tabla->index(['solicitud_cambio_id', 'id'], 'ix_sol_cambio_historial');
            $tabla->foreign('distribuidora_id', 'fk_sol_cambio_hist_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign('solicitud_cambio_id', 'fk_sol_cambio_hist_2')->references('id')->on('solicitudes_cambio')->onDelete('cascade');
            $tabla->foreign('staff_id', 'fk_sol_cambio_hist_3')->references('id')->on('distribuidora_staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cambio_historial');
    }
};
