<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, K7) — Descuento mayorista de la distribuidora.
 *
 * Decisión D8 del diseño: el precio de MENUDEO es el del catálogo y es igual
 * para todos; el de MAYOREO lo pone cada distribuidora, como un porcentaje de
 * descuento general sobre el de catálogo (y, opcionalmente, un precio propio
 * por producto, que llega en la Ola 2 con ofertas_distribuidora).
 *
 * Se agrega desde ahora, aunque se empiece a usar en la Ola 2, para que la
 * columna ya exista cuando se arme el cálculo del precio efectivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->decimal('descuento_mayorista_pct', 5, 2)
                ->default(0)
                ->after('zona_horaria');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn('descuento_mayorista_pct');
        });
    }
};
