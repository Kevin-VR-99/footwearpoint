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
        Schema::create('auditorias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usuario_id')->nullable()->index('fk_auditorias_1');
            $table->unsignedBigInteger('distribuidora_id')->nullable();
            $table->string('accion', 100);
            $table->string('entidad_tipo', 100);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_previos')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['entidad_tipo', 'entidad_id'], 'ix_auditoria_entidad');
            $table->index(['distribuidora_id', 'created_at'], 'ix_auditoria_tenant_fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
