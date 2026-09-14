<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-144 (E16-03) — Ligar cada celular a la sesión con la que se registró.
 *
 * Antes dispositivos_fcm solo se ligaba al usuario. Al revocar un token de
 * Sanctum (cerrar sesión, restablecer contraseña, cuenta desactivada, cambiar
 * contraseña cerrando los demás celulares) el celular perdía la sesión pero
 * seguía recibiendo notificaciones push de esa cuenta.
 *
 * Con ON DELETE CASCADE lo hace la base de datos sola: cada vez que se borra
 * un token de Sanctum, se borra el celular ligado a él. Así no hay que
 * acordarse de limpiarlo en cada lugar donde se revoca un token.
 *
 * Nullable porque los celulares registrados antes de este cambio no tienen
 * esa liga. En la práctica la tabla está vacía: la app todavía no registra
 * celulares.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispositivos_fcm', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('personal_access_token_id')->nullable()->after('usuario_id');

            $tabla->foreign('personal_access_token_id', 'fk_dispositivos_fcm_token')
                ->references('id')
                ->on('personal_access_tokens')
                ->onUpdate('restrict')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('dispositivos_fcm', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_dispositivos_fcm_token');
            $tabla->dropColumn('personal_access_token_id');
        });
    }
};
