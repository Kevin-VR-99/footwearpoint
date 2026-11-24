<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-377 — Aprobar un catálogo pasa a la cola.
 *
 * Aprobar 30 productos tarda unos 70 segundos (cuatro viajes al bucket por
 * producto) y el servidor web corta a los 30. Se va al worker, que corre con
 * `queue:work --timeout=1800`, igual que la lectura con IA de TG-238.
 *
 * Hacen falta dos cosas en la tabla:
 *
 *  - Un estado «aprobando». NO se reusa «procesando»: ese ya significa
 *    "leyendo con IA" y es el que alimenta la tarjeta «Leyendo con IA» del
 *    panel y los textos de avance por página. Reusarlo haría que el panel
 *    dijera que está leyendo un catálogo que en realidad está aprobando.
 *  - Dónde dejar el resumen. Antes vivía en memoria del componente y se
 *    perdía al recargar; ahora lo escribe el worker y lo lee la pantalla, así
 *    que tiene que estar en la base.
 *
 * El MODIFY del enum va en SQL a mano porque Schema no sabe cambiar enums.
 * Funciona igual en MySQL 8 y en MariaDB, que es lo que usa el equipo.
 */
return new class extends Migration
{
    private const ANTES = "'cargado','procesando','requiere_revision','aprobado','rechazado','error'";

    private const DESPUES = "'cargado','procesando','aprobando','requiere_revision','aprobado','rechazado','error'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `importaciones_catalogo` MODIFY `estado` ENUM('
            .self::DESPUES.") NOT NULL DEFAULT 'cargado'"
        );

        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->json('resumen_aprobacion')->nullable()->after('mensaje_error');
        });
    }

    public function down(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropColumn('resumen_aprobacion');
        });

        // Si algo se quedó «aprobando» cuando se echa atrás, vuelve a lo que
        // era antes de presionar el botón; si no, el MODIFY lo dejaría vacío.
        DB::table('importaciones_catalogo')
            ->where('estado', 'aprobando')
            ->update(['estado' => 'requiere_revision']);

        DB::statement(
            'ALTER TABLE `importaciones_catalogo` MODIFY `estado` ENUM('
            .self::ANTES.") NOT NULL DEFAULT 'cargado'"
        );
    }
};
