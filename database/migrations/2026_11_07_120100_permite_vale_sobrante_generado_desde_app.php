<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-356 — Autoría de un vale de sobrante generado desde la app.
 *
 * vales.creado_por_staff_id era NOT NULL a propósito (2026_09_12): emitir saldo
 * nuevo es solo del personal. Un sobrante no es saldo nuevo: es la diferencia de
 * un vale que ya existía, y puede nacer cuando el propio cliente usa su vale en
 * la app. Para no inventarle un empleado (suplantación), se guarda el usuario
 * que hizo la operación y el staff queda NULL SOLO en ese caso.
 *
 * CHECK con COALESCE: en SQL un CHECK que evalúa a NULL PASA, así que
 * "tipo = 'sobrante'" con tipo NULL dejaba entrar un vale sin staff.
 * La regla se cierra en la base con un CHECK: sin staff solo puede existir un
 * vale de tipo sobrante, con vale de origen y con usuario autor. Todo lo demás
 * sigue exigiendo staff como antes.
 *
 * MySQL/MariaDB no dejan alterar una columna con llave foránea: se tira fk_vales_5
 * y se vuelve a crear idéntica (mismo patrón que 2026_09_12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_usuario_id')->nullable()->after('creado_por_staff_id')
                ->comment('Usuario que generó el vale (TG-356). Obligatorio si no hay staff.');
            // Sin llave foránea a propósito: el autor es dato de auditoría y debe
            // sobrevivir si la cuenta se borra (EliminarPersonaAction /
            // EliminarDistribuidoraAction borran usuarios); con RESTRICT esos
            // borrados fallarían y MySQL 8 no admite SET NULL en columnas de un CHECK.
            $table->index('creado_por_usuario_id', 'ix_vales_usuario_autor');
            $table->dropForeign('fk_vales_5');
        });
        Schema::table('vales', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_staff_id')->nullable()->change();
        });
        Schema::table('vales', function (Blueprint $table) {
            $table->foreign(['distribuidora_id', 'creado_por_staff_id'], 'fk_vales_5')->references(['distribuidora_id', 'id'])
                ->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
        DB::statement("ALTER TABLE vales ADD CONSTRAINT chk_vale_autor CHECK (creado_por_staff_id IS NOT NULL OR (COALESCE(tipo, '') = 'sobrante' AND vale_origen_id IS NOT NULL AND creado_por_usuario_id IS NOT NULL))");
    }

    /** Si ya hay sobrantes sin staff, no se adivina a qué empleado asignarlos: se detiene. */
    public function down(): void
    {
        if (DB::table('vales')->whereNull('creado_por_staff_id')->exists()) {
            throw new RuntimeException('Hay vales de sobrante generados desde la app (sin staff); decide su autoría antes de revertir.');
        }
        DB::statement('ALTER TABLE vales DROP CONSTRAINT chk_vale_autor');
        Schema::table('vales', function (Blueprint $table) {
            $table->dropForeign('fk_vales_5');
        });
        Schema::table('vales', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_staff_id')->nullable(false)->change();
        });
        Schema::table('vales', function (Blueprint $table) {
            $table->foreign(['distribuidora_id', 'creado_por_staff_id'], 'fk_vales_5')->references(['distribuidora_id', 'id'])
                ->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
            $table->dropIndex('ix_vales_usuario_autor');
            $table->dropColumn('creado_por_usuario_id');
        });
    }
};
