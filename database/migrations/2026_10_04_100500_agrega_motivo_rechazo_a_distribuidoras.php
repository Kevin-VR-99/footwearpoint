<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, G4) — Por qué se rechazó una solicitud de distribuidora.
 *
 * El estado 'rechazada' ya existía, pero no había dónde guardar el motivo, así
 * que quien pedía el alta no se enteraba de qué le faltó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->string('motivo_rechazo', 300)->nullable()->after('fecha_aprobacion');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->dropColumn('motivo_rechazo');
        });
    }
};
