<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El teléfono público puede ser solo para llamadas. El botón de escribir por
 * WhatsApp en la tienda no aparece hasta que la distribuidora lo activa en su perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->boolean('telefono_es_whatsapp')->default(false)->after('telefono_publico');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->dropColumn('telefono_es_whatsapp');
        });
    }
};
