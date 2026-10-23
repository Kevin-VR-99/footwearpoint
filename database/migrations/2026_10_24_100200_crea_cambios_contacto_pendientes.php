<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio de correo o teléfono que pidió el propio cliente desde la app. No se
 * aplica hasta que abre el enlace firmado que le llega por correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_contacto_pendientes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $tabla->string('tipo', 20); // email | telefono
            $tabla->string('valor_anterior', 255)->nullable();
            $tabla->string('valor_nuevo', 255);
            $tabla->timestamp('expira_en');
            $tabla->timestamp('confirmado_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['usuario_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_contacto_pendientes');
    }
};
