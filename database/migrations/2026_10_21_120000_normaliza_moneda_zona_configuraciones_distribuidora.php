<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moneda y zona horaria quedan fijas (MXN / America/Mexico_City). No se borran
 * las columnas; solo se normalizan los valores existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuraciones_distribuidora')->update([
            'moneda' => 'MXN',
            'zona_horaria' => 'America/Mexico_City',
        ]);
    }

    public function down(): void
    {
        // Sin reversa: los valores anteriores eran equivalentes o inválidos.
    }
};
