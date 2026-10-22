<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reembolsos automáticos en Mercado Pago.
 *
 * Un renglón por cada devolución que se pide a la API de Mercado Pago
 * (POST /v1/payments/{id}/refunds) al cancelar una venta o devolver un
 * anticipo cobrado con Mercado Pago. Guarda el estado, el id del reembolso
 * en Mercado Pago, el monto, los intentos y el último error, para que el
 * personal lo vea en la pantalla y pueda reintentarlo.
 *
 * Aditiva: no toca ninguna tabla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reembolsos_mercado_pago', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            // El cobro original (pagos.metodo = mercado_pago, entrada).
            $tabla->unsignedBigInteger('pago_id');
            // La salida que asienta la devolución en nuestros pagos.
            $tabla->unsignedBigInteger('pago_reembolso_id')->nullable();
            $tabla->unsignedBigInteger('venta_directa_id')->nullable();
            $tabla->unsignedBigInteger('pedido_id')->nullable();
            // Id del pago en Mercado Pago (pagos.referencia_externa).
            $tabla->string('mp_pago_id', 30);
            $tabla->decimal('monto', 12, 2);
            $tabla->boolean('parcial')->default(false);
            // pendiente | procesando | aprobado | fallido
            $tabla->string('estado', 20)->default('pendiente');
            $tabla->string('mp_reembolso_id', 30)->nullable();
            $tabla->string('mp_estado', 30)->nullable();
            // Misma llave en cada reintento: Mercado Pago no devuelve dos veces.
            $tabla->uuid('idempotency_key')->unique();
            $tabla->unsignedSmallInteger('intentos')->default(0);
            $tabla->string('ultimo_error', 500)->nullable();
            $tabla->string('motivo', 300)->nullable();
            $tabla->unsignedBigInteger('solicitado_por_staff_id')->nullable();
            $tabla->timestamp('aprobado_at')->nullable();
            $tabla->timestamps();

            $tabla->index(['distribuidora_id', 'estado'], 'ix_reemb_mp_estado');
            $tabla->index('venta_directa_id', 'ix_reemb_mp_venta');
            $tabla->index('pedido_id', 'ix_reemb_mp_pedido');
            $tabla->index('pago_id', 'ix_reemb_mp_pago');
            $tabla->foreign('distribuidora_id', 'fk_reemb_mp_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign('pago_id', 'fk_reemb_mp_2')->references('id')->on('pagos')->onDelete('cascade');
            $tabla->foreign('pago_reembolso_id', 'fk_reemb_mp_3')->references('id')->on('pagos')->nullOnDelete();
            $tabla->foreign('solicitado_por_staff_id', 'fk_reemb_mp_4')->references('id')->on('distribuidora_staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reembolsos_mercado_pago');
    }
};
