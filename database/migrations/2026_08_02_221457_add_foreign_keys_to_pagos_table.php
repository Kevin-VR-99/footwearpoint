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
        Schema::table('pagos', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_pagos_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_id'], 'fk_pagos_2')->references(['distribuidora_id', 'id'])->on('pedidos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'venta_directa_id'], 'fk_pagos_3')->references(['distribuidora_id', 'id'])->on('ventas_directas')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'registrado_por_staff_id'], 'fk_pagos_4')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropForeign('fk_pagos_1');
            $table->dropForeign('fk_pagos_2');
            $table->dropForeign('fk_pagos_3');
            $table->dropForeign('fk_pagos_4');
        });
    }
};
