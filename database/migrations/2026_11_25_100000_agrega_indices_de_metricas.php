<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-397 (A37) — Índices para las consultas de Métricas.
 *
 * Métricas pregunta casi siempre por TODA la plataforma (sin distribuidora).
 * Los índices que ya había en pagos y ventas_directas empiezan por
 * distribuidora_id, porque están pensados para la pantalla de cada tienda, y
 * sin ese filtro la base no los puede usar: recorría la tabla entera.
 *
 *   - pagos (tipo, estado, fecha_pago): «pagos de suscripción aplicados entre
 *     estas fechas», que es exactamente el filtro de los ingresos.
 *   - ventas_directas (fecha_venta): las ventas de tienda por rango de fechas.
 *   - suscripciones (estado, plan_id, distribuidora_id): «plan más
 *     contratado» cuenta distribuidoras distintas con suscripción activa por
 *     plan, y con este índice lo resuelve sin leer las filas.
 *   - actividad_diaria (fecha, usuario_id): contar personas distintas en un
 *     rango de fechas sin leer las filas.
 *
 * Solo agrega índices: no cambia columnas ni datos, y down() quita
 * exactamente lo que up() puso. Ninguna llave foránea depende de ellos (cada
 * una ya tiene el suyo desde antes), así que se pueden quitar sin problema.
 */
return new class extends Migration
{
    private const INDICES = [
        'pagos' => ['ix_pagos_tipo_estado_fecha', ['tipo', 'estado', 'fecha_pago']],
        'ventas_directas' => ['ix_ventas_directas_fecha', ['fecha_venta']],
        'suscripciones' => ['ix_suscripciones_estado_plan', ['estado', 'plan_id', 'distribuidora_id']],
        'actividad_diaria' => ['ix_actividad_diaria_fecha_usuario', ['fecha', 'usuario_id']],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => [$nombre, $columnas]) {
            if (Schema::hasIndex($tabla, $nombre)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) use ($nombre, $columnas) {
                $t->index($columnas, $nombre);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => [$nombre]) {
            if (! Schema::hasIndex($tabla, $nombre)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) use ($nombre) {
                $t->dropIndex($nombre);
            });
        }
    }
};
