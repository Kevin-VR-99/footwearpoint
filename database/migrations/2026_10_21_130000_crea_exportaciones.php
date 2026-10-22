<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Exportaciones (PDF/Excel) que se generan en la cola. Idempotente. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exportaciones')) {
            return;
        }

        Schema::create('exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('formato', 10);
            $table->json('filtros')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->string('disco', 40)->nullable();
            $table->string('ruta', 255)->nullable();
            $table->string('nombre_archivo', 150)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('expira_at')->nullable();
            $table->timestamps();

            $table->index(['distribuidora_id', 'usuario_id', 'created_at'], 'ix_exportaciones_usuario');
            $table->index(['estado', 'expira_at'], 'ix_exportaciones_vencidas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportaciones');
    }
};
