<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría 21-oct-2026 (punto 3): versiones WebP redimensionadas de cada
 * foto de producto. Son opcionales: las fotos que ya existen quedan con
 * null y se sigue usando la original (url).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_imagenes', function (Blueprint $table) {
            $table->string('url_miniatura', 500)->nullable()->after('url')
                ->comment('WebP de ~400 px de ancho para listados; null en fotos anteriores.');
            $table->string('url_mediana', 500)->nullable()->after('url_miniatura')
                ->comment('WebP de ~1200 px de ancho para el detalle; null en fotos anteriores.');
        });
    }

    public function down(): void
    {
        Schema::table('producto_imagenes', function (Blueprint $table) {
            $table->dropColumn(['url_miniatura', 'url_mediana']);
        });
    }
};
