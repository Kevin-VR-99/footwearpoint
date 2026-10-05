<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, G9) — Avisos que manda Mercado Pago (webhooks).
 *
 * Mercado Pago avisa por su cuenta cuando un pago cambia, y puede mandar el
 * MISMO aviso varias veces. Por eso cada aviso se guarda aquí antes de
 * procesarlo, con un único (tipo, recurso_id): si llega repetido, la base lo
 * rechaza y no se cobra ni se activa nada dos veces.
 *
 * Se guarda el aviso completo (payload) para poder revisar después qué mandó
 * Mercado Pago, y el error si algo falló al procesarlo.
 *
 * distribuidora_id acepta nulo: cuando llega el aviso todavía no siempre se
 * sabe de quién es, y se llena al procesarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks_mercado_pago', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('tipo', 60);
            $tabla->string('recurso_id', 190);
            $tabla->unsignedBigInteger('distribuidora_id')->nullable();
            $tabla->json('payload');
            $tabla->dateTime('procesado_at')->nullable();
            $tabla->text('error')->nullable();
            $tabla->dateTime('created_at')->useCurrent();

            $tabla->unique(['tipo', 'recurso_id'], 'uq_webhook_mp_tipo_recurso');
            $tabla->index('procesado_at', 'ix_webhook_mp_procesado');

            $tabla->foreign('distribuidora_id', 'fk_webhooks_mercado_pago_1')
                ->references('id')
                ->on('distribuidoras')
                ->onUpdate('restrict')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks_mercado_pago');
    }
};
