<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelar (anular) una venta de mostrador.
 *
 * Aditiva: el estado 'anulada' ya existía en ventas_directas; aquí se agrega
 * quién, cuándo y por qué, y el tipo de pago 'reembolso_venta' (salida) para
 * dejar asentada la devolución del dinero sin tocar el cobro original.
 */
return new class extends Migration
{
    private const TIPOS_ANTES = "'anticipo','saldo_pedido','total_revendedor','venta_directa','reembolso_anticipo','suscripcion'";

    private const TIPOS_DESPUES = "'anticipo','saldo_pedido','total_revendedor','venta_directa','reembolso_anticipo','suscripcion','reembolso_venta'";

    public function up(): void
    {
        Schema::table('ventas_directas', function (Blueprint $tabla) {
            $tabla->dateTime('anulada_at')->nullable()->after('estado');
            $tabla->unsignedBigInteger('anulada_por_staff_id')->nullable()->after('anulada_at');
            $tabla->string('motivo_anulacion', 300)->nullable()->after('anulada_por_staff_id');

            $tabla->foreign(['distribuidora_id', 'anulada_por_staff_id'], 'fk_ventas_directas_anulada_por')
                ->references(['distribuidora_id', 'id'])->on('distribuidora_staff')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        DB::statement('ALTER TABLE `pagos` MODIFY `tipo` ENUM('.self::TIPOS_DESPUES.') NOT NULL');
    }

    public function down(): void
    {
        DB::statement("DELETE FROM `pagos` WHERE `tipo` = 'reembolso_venta'");
        DB::statement('ALTER TABLE `pagos` MODIFY `tipo` ENUM('.self::TIPOS_ANTES.') NOT NULL');

        Schema::table('ventas_directas', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_ventas_directas_anulada_por');
            $tabla->dropColumn(['anulada_at', 'anulada_por_staff_id', 'motivo_anulacion']);
        });
    }
};
