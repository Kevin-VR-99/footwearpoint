<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->foreign(['usuario_id'], 'fk_notificaciones_1')->references(['id'])->on('usuarios')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id'], 'fk_notificaciones_2')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropForeign('fk_notificaciones_1');
            $table->dropForeign('fk_notificaciones_2');
        });
    }
};
