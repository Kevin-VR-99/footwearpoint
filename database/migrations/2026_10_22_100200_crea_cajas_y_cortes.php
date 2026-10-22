<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corte de caja del punto de venta.
 *
 * Una sesión de caja la abre una persona del personal en una sucursal, con un
 * fondo inicial; mientras está abierta, los cobros y reembolsos que esa
 * persona registra quedan ligados a ella (pagos.caja_sesion_id). Al cerrar se
 * guarda lo esperado y lo contado por método de pago.
 *
 * Aditiva: pagos.caja_sesion_id es opcional; los pagos de antes quedan sin caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_sesiones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('sucursal_id');
            $tabla->unsignedBigInteger('abierta_por_staff_id');
            $tabla->unsignedBigInteger('cerrada_por_staff_id')->nullable();
            $tabla->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $tabla->decimal('fondo_inicial', 12, 2)->default(0);
            $tabla->dateTime('abierta_at');
            $tabla->dateTime('cerrada_at')->nullable();
            $tabla->json('esperado')->nullable();
            $tabla->json('contado')->nullable();
            $tabla->decimal('diferencia', 12, 2)->nullable();
            $tabla->string('notas', 500)->nullable();
            $tabla->timestamps();

            $tabla->unique(['distribuidora_id', 'id'], 'uq_cajas_sesiones_tenant_id');
            $tabla->index(['distribuidora_id', 'estado', 'abierta_por_staff_id'], 'ix_cajas_sesiones_abiertas');
            $tabla->foreign('distribuidora_id', 'fk_cajas_sesiones_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign(['distribuidora_id', 'sucursal_id'], 'fk_cajas_sesiones_2')->references(['distribuidora_id', 'id'])->on('sucursales');
            $tabla->foreign(['distribuidora_id', 'abierta_por_staff_id'], 'fk_cajas_sesiones_3')->references(['distribuidora_id', 'id'])->on('distribuidora_staff');
            $tabla->foreign(['distribuidora_id', 'cerrada_por_staff_id'], 'fk_cajas_sesiones_4')->references(['distribuidora_id', 'id'])->on('distribuidora_staff');
        });

        Schema::create('cajas_movimientos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('caja_sesion_id');
            $tabla->enum('tipo', ['ingreso', 'retiro']);
            $tabla->decimal('monto', 12, 2);
            $tabla->string('motivo', 300);
            $tabla->unsignedBigInteger('registrado_por_staff_id');
            $tabla->dateTime('created_at')->useCurrent();

            $tabla->unique(['distribuidora_id', 'id'], 'uq_cajas_movimientos_tenant_id');
            $tabla->foreign('distribuidora_id', 'fk_cajas_movimientos_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign(['distribuidora_id', 'caja_sesion_id'], 'fk_cajas_movimientos_2')->references(['distribuidora_id', 'id'])->on('cajas_sesiones');
            $tabla->foreign(['distribuidora_id', 'registrado_por_staff_id'], 'fk_cajas_movimientos_3')->references(['distribuidora_id', 'id'])->on('distribuidora_staff');
        });

        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('caja_sesion_id')->nullable()->after('registrado_por_staff_id');
            $tabla->foreign(['distribuidora_id', 'caja_sesion_id'], 'fk_pagos_caja_sesion')
                ->references(['distribuidora_id', 'id'])->on('cajas_sesiones');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_pagos_caja_sesion');
            $tabla->dropColumn('caja_sesion_id');
        });

        Schema::dropIfExists('cajas_movimientos');
        Schema::dropIfExists('cajas_sesiones');
    }
};
