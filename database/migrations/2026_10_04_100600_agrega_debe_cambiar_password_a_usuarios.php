<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, A2) — Contraseña temporal.
 *
 * Cuando la distribuidora le crea la cuenta a alguien, le pone una contraseña
 * temporal. Con esta bandera el sistema sabe que esa persona tiene que
 * cambiarla antes de seguir usando la app o el panel.
 *
 * Falso por omisión: las cuentas que ya existen no cambian de comportamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->boolean('debe_cambiar_password')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->dropColumn('debe_cambiar_password');
        });
    }
};
