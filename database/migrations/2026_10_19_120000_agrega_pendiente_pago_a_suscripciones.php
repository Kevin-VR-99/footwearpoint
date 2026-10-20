<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TG-220 — Cobro antes de acceder: al aprobar una distribuidora su primera
 * suscripción queda en 'pendiente_pago' hasta que paga el primer mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE suscripciones MODIFY estado ENUM('prueba','activa','vencida','suspendida','cancelada','pendiente_pago') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('suscripciones')->where('estado', 'pendiente_pago')->update(['estado' => 'cancelada']);
        DB::statement("ALTER TABLE suscripciones MODIFY estado ENUM('prueba','activa','vencida','suspendida','cancelada') NOT NULL");
    }
};
