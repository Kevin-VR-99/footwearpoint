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
        Schema::table('solicitudes_cambio', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_solicitudes_cambio_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_detalle_id'], 'fk_solicitudes_cambio_2')->references(['distribuidora_id', 'id'])->on('pedido_detalle')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'venta_detalle_id'], 'fk_solicitudes_cambio_3')->references(['distribuidora_id', 'id'])->on('venta_directa_detalle')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'cliente_directo_id'], 'fk_solicitudes_cambio_4')->references(['distribuidora_id', 'id'])->on('clientes_directos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'revendedor_distribuidora_id'], 'fk_solicitudes_cambio_5')->references(['distribuidora_id', 'id'])->on('revendedor_distribuidora')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'vale_generado_id'], 'fk_solicitudes_cambio_6')->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitudes_cambio', function (Blueprint $table) {
            $table->dropForeign('fk_solicitudes_cambio_1');
            $table->dropForeign('fk_solicitudes_cambio_2');
            $table->dropForeign('fk_solicitudes_cambio_3');
            $table->dropForeign('fk_solicitudes_cambio_4');
            $table->dropForeign('fk_solicitudes_cambio_5');
            $table->dropForeign('fk_solicitudes_cambio_6');
        });
    }
};
