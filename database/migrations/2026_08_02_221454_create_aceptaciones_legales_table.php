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
        Schema::create('aceptaciones_legales', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('usuario_id');
            $table->enum('tipo_documento', ['aviso_privacidad', 'terminos_condiciones']);
            $table->string('version', 30);
            $table->dateTime('fecha_aceptacion')->useCurrent();
            $table->string('ip_origen', 45)->nullable();

            $table->unique(['usuario_id', 'tipo_documento', 'version'], 'uq_aceptacion_usuario_tipo_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aceptaciones_legales');
    }
};
