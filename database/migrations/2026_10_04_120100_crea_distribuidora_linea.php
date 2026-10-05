<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-210 (Ola 2, K3) — Qué líneas vende cada distribuidora.
 *
 * El catálogo es uno solo (K2), pero cada distribuidora trabaja solo algunas
 * líneas: las que su plan le permite. Esta tabla es esa decisión suya
 * (sección 4.2 del diseño).
 *
 * Desactivar una línea NO borra la fila: así queda el historial de qué vendió
 * y desde cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidora_linea', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('linea_id');

            // Informativo: la activación pasó de las líneas incluidas del plan
            // y cayó dentro de las extras contratadas. El cobro de K4 se
            // calcula contando las activas por encima de las incluidas, no
            // sumando esta bandera.
            $tabla->boolean('es_extra')->default(false);

            $tabla->boolean('activa')->default(true);
            $tabla->dateTime('fecha_activacion')->nullable();
            $tabla->dateTime('created_at')->useCurrent();
            $tabla->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $tabla->unique(['distribuidora_id', 'linea_id'], 'uq_distribuidora_linea');
            $tabla->index(['distribuidora_id', 'activa'], 'ix_distribuidora_linea_activa');

            $tabla->foreign('distribuidora_id', 'fk_distribuidora_linea_distribuidora')
                ->references('id')->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign('linea_id', 'fk_distribuidora_linea_linea')
                ->references('id')->on('lineas')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidora_linea');
    }
};
