<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, G6) — Credenciales de Mercado Pago por distribuidora.
 *
 * Cada distribuidora cobra con SU propia cuenta de Mercado Pago, así que sus
 * credenciales se guardan aquí y no en el .env del servidor.
 *
 * mp_access_token se guarda cifrado: el cast 'encrypted' del modelo
 * ConfiguracionDistribuidora lo cifra al guardar y lo descifra al leer, por
 * eso la columna es text (el texto cifrado es mucho más largo que el token).
 *
 * mercado_pago_account_id ya existía desde el esquema inicial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->text('mp_access_token')->nullable()->after('mercado_pago_account_id');
            $tabla->string('mp_public_key', 190)->nullable()->after('mp_access_token');
            $tabla->dateTime('mp_conectado_at')->nullable()->after('mp_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn(['mp_access_token', 'mp_public_key', 'mp_conectado_at']);
        });
    }
};
