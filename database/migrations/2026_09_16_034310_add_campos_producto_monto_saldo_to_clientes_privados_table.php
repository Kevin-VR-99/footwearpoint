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
        Schema::table('clientes_privados_revendedor', function (Blueprint $table) {
            $table->string('producto', 190)->nullable()->after('telefono');
            $table->decimal('monto', 10, 2)->default(0)->after('producto');
            $table->decimal('saldo', 10, 2)->default(0)->after('monto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes_privados_revendedor', function (Blueprint $table) {
            $table->dropColumn(['producto', 'monto', 'saldo']);
        });
    }
};