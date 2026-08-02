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
        Schema::create('clientes_privados_revendedor', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('revendedor_id');
            $table->string('nombre', 150);
            $table->string('telefono', 30)->nullable();
            $table->string('referencia', 150)->nullable();
            $table->text('notas')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['revendedor_id', 'nombre'], 'uq_cliente_privado_revendedor_nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes_privados_revendedor');
    }
};
