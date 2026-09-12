<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-138 — Desde la app móvil, un cliente directo o un revendedor opera por
 * sí mismo: crea su pedido, lo envía, aplica su vale. En esos casos NO hay
 * un empleado que haya capturado la operación.
 *
 * Estas columnas eran NOT NULL con llave foránea obligatoria a
 * distribuidora_staff, así que no existía ningún valor válido que guardar y
 * la operación reventaba.
 *
 * A partir de aquí, NULL significa "lo hizo el propio cliente o revendedor
 * desde la app". No se pierde información: de quién es la operación se sigue
 * sabiendo por cliente_directo_id / revendedor_distribuidora_id, que no
 * cambian. Esta columna solo dice quién la capturó.
 *
 * vales.creado_por_staff_id se queda NOT NULL a propósito: emitir un vale es
 * entregar saldo, y eso solo lo hace la distribuidora. Un cliente únicamente
 * aplica vales que ya le dieron.
 *
 * Nota para quien lea el diff: MySQL no deja alterar una columna que forma
 * parte de una llave foránea, por eso cada una se tira y se vuelve a crear
 * idéntica (mismo nombre, mismas reglas restrict).
 */
return new class extends Migration
{
    private array $columnas = [
        ['tabla' => 'pedidos',                  'columna' => 'capturado_por_staff_id',  'fk' => 'fk_pedidos_6'],
        ['tabla' => 'pagos',                    'columna' => 'registrado_por_staff_id', 'fk' => 'fk_pagos_4'],
        ['tabla' => 'historial_estados_pedido', 'columna' => 'cambiado_por_staff_id',   'fk' => 'fk_historial_estados_pedido_3'],
        ['tabla' => 'vale_movimientos',         'columna' => 'registrado_por_staff_id', 'fk' => 'fk_vale_movimientos_5'],
    ];

    public function up(): void
    {
        $this->aplicar(true);
    }

    /**
     * Ojo al revertir: si ya existen filas con NULL (operaciones hechas desde
     * la app), volver a NOT NULL falla. Habría que decidir primero qué staff
     * se les asigna.
     */
    public function down(): void
    {
        $this->aplicar(false);
    }

    private function aplicar(bool $nullable): void
    {
        foreach ($this->columnas as $item) {
            Schema::table($item['tabla'], function (Blueprint $tabla) use ($item) {
                $tabla->dropForeign($item['fk']);
            });

            Schema::table($item['tabla'], function (Blueprint $tabla) use ($item, $nullable) {
                $tabla->unsignedBigInteger($item['columna'])->nullable($nullable)->change();
            });

            Schema::table($item['tabla'], function (Blueprint $tabla) use ($item) {
                $tabla->foreign(['distribuidora_id', $item['columna']], $item['fk'])
                    ->references(['distribuidora_id', 'id'])
                    ->on('distribuidora_staff')
                    ->onUpdate('restrict')
                    ->onDelete('restrict');
            });
        }
    }
};
