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
        Schema::create('clientes_directos', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('usuario_id')->nullable()->index('fk_clientes_directos_2');
            $table->string('nombre', 150);
            $table->string('telefono', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('direccion_contacto', 300)->nullable();
            $table->text('notas')->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'nombre'], 'ix_cliente_tenant_nombre');
            $table->unique(['distribuidora_id', 'id'], 'uq_clientes_directos_tenant_id');
            $table->unique(['distribuidora_id', 'usuario_id'], 'uq_cliente_tenant_usuario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes_directos');
    }
};
