<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas estándar de Laravel para SESSION_DRIVER=database y
 * CACHE_STORE=database. Con sesiones y caché en archivo, cada deploy en
 * Railway cerraba la sesión de todos y los límites de intentos se contaban
 * por contenedor.
 *
 * Idempotente: si la tabla ya existe (creada a mano o por otra rama) se salta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $tabla) {
                $tabla->string('id')->primary();
                $tabla->foreignId('user_id')->nullable()->index();
                $tabla->string('ip_address', 45)->nullable();
                $tabla->text('user_agent')->nullable();
                $tabla->longText('payload');
                $tabla->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $tabla) {
                $tabla->string('key')->primary();
                $tabla->mediumText('value');
                $tabla->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $tabla) {
                $tabla->string('key')->primary();
                $tabla->string('owner');
                $tabla->integer('expiration')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
    }
};
