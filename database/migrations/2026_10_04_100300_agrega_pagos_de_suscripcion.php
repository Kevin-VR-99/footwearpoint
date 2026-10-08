<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, G7 a G11) — Pagos de suscripción con Mercado Pago.
 *
 * Hasta hoy la tabla pagos solo guardaba cobros de pedidos y ventas. Con
 * Mercado Pago también guarda lo que la distribuidora le paga a FootwearPoint
 * por su suscripción:
 *
 *   - tipo 'suscripcion' (valor nuevo del enum; los que ya existían no se
 *     tocan, así que ningún pago actual cambia);
 *   - suscripcion_id para saber cuál mensualidad se está pagando;
 *   - preferencia_externa, el id de la preferencia de Checkout Pro, que es
 *     con lo que se liga el aviso (webhook) que manda Mercado Pago.
 *
 * La llave de suscripcion_id es doble, como el resto de la base: así la base
 * impide ligar un pago con la suscripción de otra distribuidora.
 */
return new class extends Migration
{
    private const TIPOS_ANTES = "'anticipo','saldo_pedido','total_revendedor','venta_directa','reembolso_anticipo'";

    private const TIPOS_DESPUES = "'anticipo','saldo_pedido','total_revendedor','venta_directa','reembolso_anticipo','suscripcion'";

    public function up(): void
    {
        DB::statement('ALTER TABLE `pagos` MODIFY `tipo` ENUM('.self::TIPOS_DESPUES.') NOT NULL');

        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('suscripcion_id')->nullable()->after('venta_directa_id');
            $tabla->string('preferencia_externa', 190)->nullable()->after('referencia_externa');

            $tabla->foreign(['distribuidora_id', 'suscripcion_id'], 'fk_pagos_5')
                ->references(['distribuidora_id', 'id'])
                ->on('suscripciones')
                ->onUpdate('restrict')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_pagos_5');
            $tabla->dropColumn(['suscripcion_id', 'preferencia_externa']);
        });

        DB::statement('ALTER TABLE `pagos` MODIFY `tipo` ENUM('.self::TIPOS_ANTES.') NOT NULL');
    }
};
