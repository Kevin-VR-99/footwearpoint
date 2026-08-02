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
        Schema::create('distribuidoras', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre_comercial', 150);
            $table->string('razon_social', 200)->nullable();
            $table->string('rfc', 13)->nullable()->unique('uq_distribuidora_rfc');
            $table->string('slug', 120)->unique('uq_distribuidora_slug');
            $table->string('subdominio', 120)->nullable()->unique('uq_distribuidora_subdominio');
            $table->string('logotipo_url', 500)->nullable();
            $table->text('descripcion_publica')->nullable();
            $table->string('direccion_publica', 300)->nullable();
            $table->string('telefono_publico', 30)->nullable();
            $table->string('email_publico', 190)->nullable();
            $table->string('horario_publico', 300)->nullable();
            $table->boolean('marketplace_visible')->default(false);
            $table->enum('estado', ['pendiente', 'activa', 'suspendida', 'rechazada'])->default('pendiente');
            $table->dateTime('fecha_solicitud')->useCurrent();
            $table->dateTime('fecha_aprobacion')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribuidoras');
    }
};
