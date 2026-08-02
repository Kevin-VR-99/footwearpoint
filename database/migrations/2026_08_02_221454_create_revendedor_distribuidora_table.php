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
        Schema::create('revendedor_distribuidora', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('revendedor_id')->index('fk_revendedor_distribuidora_2');
            $table->string('codigo_interno', 60)->nullable();
            $table->enum('estado', ['activo', 'suspendido', 'inactivo'])->default('activo');
            $table->date('fecha_alta');
            $table->text('notas')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_revendedor_distribuidora_tenant_id');
            $table->unique(['distribuidora_id', 'revendedor_id'], 'uq_revendedor_tenant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revendedor_distribuidora');
    }
};
