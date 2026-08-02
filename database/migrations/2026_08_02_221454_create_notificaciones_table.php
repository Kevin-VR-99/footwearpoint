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
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('distribuidora_id')->nullable()->index('fk_notificaciones_2');
            $table->string('tipo', 60);
            $table->string('titulo', 160);
            $table->text('mensaje');
            $table->dateTime('leida_at')->nullable();
            $table->string('entidad_tipo', 80)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['usuario_id', 'leida_at'], 'ix_notificacion_usuario_leida');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
