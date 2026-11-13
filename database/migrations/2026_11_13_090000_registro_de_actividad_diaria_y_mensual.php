<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-406 (A43) — Registro de actividad.
 *
 * Dos tablas con horizontes distintos, que es lo que pide la política ya
 * aprobada:
 *
 * - actividad_diaria: el detalle, 90 días. Una fila por persona, día y canal,
 *   y nada más. SIN IP y SIN qué hizo: eso no se guarda a propósito, no es un
 *   olvido. Lo único que se responde con esta tabla es «cuánta gente distinta
 *   usó la plataforma», no «qué hizo fulano».
 *
 * - actividad_mensual: los totales, 24 meses. Se calculan antes de borrar el
 *   detalle, porque después ya no se pueden reconstruir.
 *
 * El canal 'total' de la tabla mensual NO es la suma de panel + app: es la
 * gente distinta entre los dos. Quien entra por los dos lados cuenta una vez,
 * y por eso hay que guardarlo aparte en vez de sumarlo al leer.
 *
 * char(7) para el mes ('2026-11') y no una fecha: evita que alguien lo
 * interprete como un día concreto del mes, y ordena bien como texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividad_diaria', function (Blueprint $tabla) {
            $tabla->id();

            // Al borrar a una persona se van sus filas (regla de la política).
            $tabla->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();

            // El día en hora de America/Mexico_City, no en UTC: con UTC lo de
            // las 7 de la noche caería al día siguiente.
            $tabla->date('fecha');

            $tabla->string('canal', 10);

            // Una sola fila por persona, día y canal. Es lo que hace que
            // registrar dos veces el mismo día no infle la cuenta.
            $tabla->unique(['usuario_id', 'fecha', 'canal'], 'actividad_diaria_unica');

            // Para barrer lo vencido y para contar por rango de fechas.
            $tabla->index(['fecha', 'canal'], 'actividad_diaria_fecha_canal');
        });

        Schema::create('actividad_mensual', function (Blueprint $tabla) {
            $tabla->id();

            // '2026-11'.
            $tabla->char('anio_mes', 7);

            // 'panel', 'app' o 'total' (gente distinta entre los dos).
            $tabla->string('canal', 10);

            $tabla->unsignedInteger('usuarios_unicos')->default(0);

            // Cuándo se calculó, para saber si un mes quedó a medias.
            $tabla->timestamp('calculado_at')->nullable();

            // Recalcular un mes lo actualiza en vez de duplicarlo: es lo que
            // deja correr la limpieza dos veces sin que se note.
            $tabla->unique(['anio_mes', 'canal'], 'actividad_mensual_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividad_mensual');
        Schema::dropIfExists('actividad_diaria');
    }
};
