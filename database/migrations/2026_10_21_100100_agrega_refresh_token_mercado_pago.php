<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renovación del token OAuth de Mercado Pago de cada distribuidora.
 *
 * mp_refresh_token va cifrado (cast 'encrypted', igual que mp_access_token).
 * mp_token_expira_en sale del expires_in que da Mercado Pago; en las
 * conexiones viejas queda NULL y se estima con mp_conectado_at + 180 días.
 * mp_aviso_reconexion_at evita repetir el aviso todos los días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            if (! Schema::hasColumn('configuraciones_distribuidora', 'mp_refresh_token')) {
                $tabla->text('mp_refresh_token')->nullable()->after('mp_access_token');
            }
            if (! Schema::hasColumn('configuraciones_distribuidora', 'mp_token_expira_en')) {
                $tabla->dateTime('mp_token_expira_en')->nullable()->after('mp_conectado_at');
            }
            if (! Schema::hasColumn('configuraciones_distribuidora', 'mp_aviso_reconexion_at')) {
                $tabla->dateTime('mp_aviso_reconexion_at')->nullable()->after('mp_token_expira_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn(['mp_refresh_token', 'mp_token_expira_en', 'mp_aviso_reconexion_at']);
        });
    }
};
