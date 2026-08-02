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
        Schema::create('distribuidora_staff', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('usuario_id')->index('fk_distribuidora_staff_2');
            $table->enum('tipo', ['administrador', 'empleado']);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_alta')->useCurrent();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_distribuidora_staff_tenant_id');
            $table->unique(['distribuidora_id', 'usuario_id'], 'uq_staff_tenant_usuario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribuidora_staff');
    }
};
