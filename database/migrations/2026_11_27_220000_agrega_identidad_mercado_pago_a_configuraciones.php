<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre, correo y usuario (nickname) del titular de la cuenta de Mercado
 * Pago conectada. Se consultan en GET /users/me con el token del vendedor.
 * Columnas nulas: las cuentas que ya estaban conectadas se completan al
 * abrir la pestaña o con `php artisan mercado-pago:sincronizar-identidad`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->string('mp_nombre', 150)->nullable()->after('mercado_pago_account_id');
            $tabla->string('mp_email', 190)->nullable()->after('mp_nombre');
            $tabla->string('mp_nickname', 190)->nullable()->after('mp_email');
            $tabla->timestamp('mp_identidad_consultada_at')->nullable()->after('mp_nickname');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn([
                'mp_nombre',
                'mp_email',
                'mp_nickname',
                'mp_identidad_consultada_at',
            ]);
        });
    }
};
