<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca qué foto de la galería salió de cada recorte de la IA.
 *
 * El segundo color de un modelo comparte publicación con el primero, así
 * que su recorte se agrega a la galería de esa publicación
 * (catalogo:publicar-fotos-ia --galeria). La URL que se guarda en
 * producto_imagenes es nueva, no la del recorte, así que sin esta marca no
 * hay forma de saber si ese recorte ya se agregó.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('productos_importados_staging', 'producto_imagen_galeria_id')) {
            return;
        }

        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            // Sin llave foránea a propósito: si el admin borra esa foto de la
            // galería, la marca se queda y el comando no la vuelve a subir.
            $tabla->unsignedBigInteger('producto_imagen_galeria_id')
                ->nullable()
                ->after('foto_estado');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('productos_importados_staging', 'producto_imagen_galeria_id')) {
            return;
        }

        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->dropColumn('producto_imagen_galeria_id');
        });
    }
};