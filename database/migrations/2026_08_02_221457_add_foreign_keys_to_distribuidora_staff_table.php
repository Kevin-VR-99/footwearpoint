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
        Schema::table('distribuidora_staff', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_distribuidora_staff_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['usuario_id'], 'fk_distribuidora_staff_2')->references(['id'])->on('usuarios')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('distribuidora_staff', function (Blueprint $table) {
            $table->dropForeign('fk_distribuidora_staff_1');
            $table->dropForeign('fk_distribuidora_staff_2');
        });
    }
};
