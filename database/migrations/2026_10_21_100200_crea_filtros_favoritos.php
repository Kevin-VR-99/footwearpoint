<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Filtros favoritos por usuario y distribuidora (Reportes). Idempotente. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('filtros_favoritos')) {
            return;
        }

        Schema::create('filtros_favoritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('pantalla', 40)->default('reportes');
            $table->string('nombre', 60);
            $table->json('filtros');
            $table->timestamps();

            $table->unique(['distribuidora_id', 'usuario_id', 'pantalla', 'nombre'], 'filtros_favoritos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filtros_favoritos');
    }
};
