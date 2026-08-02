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
        Schema::create('producto_campana', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('campana_id');
            $table->string('codigo_catalogo', 120);
            $table->decimal('precio_mayorista', 12);
            $table->decimal('precio_minorista_sugerido', 12);
            $table->enum('estado_disponibilidad', ['disponible', 'bajo_pedido', 'no_disponible'])->default('bajo_pedido');
            $table->boolean('publicado')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'campana_id', 'codigo_catalogo'], 'uq_codigo_campana');
            $table->unique(['distribuidora_id', 'producto_id', 'campana_id'], 'uq_producto_campana');
            $table->unique(['distribuidora_id', 'id'], 'uq_producto_campana_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_campana');
    }
};
