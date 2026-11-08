<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-356 (G33 / S5-74) — Tipos de vale y nota de devolución de producto.
 *
 * Solo agrega columnas y una tabla; no toca migraciones existentes ni datos.
 * Compatibilidad: los vales que ya existen quedan con tipo NULL ("legacy") y
 * siguen con sus reglas de siempre. No se adivina su tipo.
 *
 * - vales.tipo: anticipo | devolucion_producto | bono | sobrante (NULL = legacy).
 * - vales.vale_origen_id: vale del que salió un vale de sobrante.
 * - vales.clave_idempotencia: evita emitir dos veces la misma nota.
 * - vale_devolucion_partidas: qué partida y cuántas unidades cubre cada nota,
 *   para el tope acumulado (no devolver dos veces lo mismo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->enum('tipo', ['anticipo', 'devolucion_producto', 'bono', 'sobrante'])->nullable()->after('estado')
                ->comment('NULL = vale anterior a TG-356 (legacy).');
            $table->unsignedBigInteger('vale_origen_id')->nullable()->after('pedido_origen_id');
            $table->string('clave_idempotencia', 64)->nullable()->after('vale_origen_id');

            $table->index(['distribuidora_id', 'vale_origen_id'], 'ix_vale_origen');
            $table->unique(['distribuidora_id', 'clave_idempotencia'], 'uq_vale_tenant_idempotencia');
            $table->foreign(['distribuidora_id', 'vale_origen_id'], 'fk_vales_origen')
                ->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::create('vale_devolucion_partidas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('distribuidora_id');
            $table->unsignedBigInteger('vale_id');
            $table->unsignedBigInteger('pedido_detalle_id');
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 12)->comment('Precio pagado registrado en la partida al emitir.');
            $table->decimal('monto', 12);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['vale_id', 'pedido_detalle_id'], 'uq_vdp_vale_partida');
            $table->index(['distribuidora_id', 'pedido_detalle_id'], 'ix_vdp_partida');
            $table->foreign(['distribuidora_id', 'vale_id'], 'fk_vdp_vale')
                ->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_detalle_id'], 'fk_vdp_partida')
                ->references(['distribuidora_id', 'id'])->on('pedido_detalle')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        // Revertir borraría el tipo, el origen y las partidas devueltas: con notas
        // reales eso pierde trazabilidad y reabre la doble devolución. No se adivina.
        if (DB::table('vales')->whereNotNull('tipo')->exists() || DB::table('vale_devolucion_partidas')->exists()) {
            throw new RuntimeException('Hay vales con tipo o partidas devueltas; revertir perdería su trazabilidad. Decide qué hacer con ellos antes.');
        }

        Schema::dropIfExists('vale_devolucion_partidas');

        Schema::table('vales', function (Blueprint $table) {
            $table->dropForeign('fk_vales_origen');
            $table->dropUnique('uq_vale_tenant_idempotencia');
            $table->dropIndex('ix_vale_origen');
            $table->dropColumn(['tipo', 'vale_origen_id', 'clave_idempotencia']);
        });
    }
};
