<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Límite de clientes mayoristas (revendedores) por plan.
 *
 * Igual que las líneas: el plan dice cuántos incluye y cuánto cuesta cada
 * uno de más al mes, y la suscripción congela esos dos números al
 * contratarse. Solo agrega columnas, con valores por defecto, así que nada
 * de lo que ya existe cambia de comportamiento:
 *
 *   - Básico (y cualquier otro plan): 25 incluidos, $10 por cada extra.
 *   - Pro: 50. Enterprise: 100.
 *   - «Prueba 5» (el plan de $5 para probar cobros reales): 5 incluidos y
 *     $1 por extra, para poder probar el cobro del extra sin gastar.
 *
 * revendedores_extra_contratados empieza en 0 para todas: quien ya tiene
 * más revendedores que el límite no pierde a ninguno ni paga por ellos;
 * solo no puede agregar más sin aceptar el cargo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes_suscripcion', function (Blueprint $table) {
            $table->unsignedInteger('revendedores_incluidos')->default(25)->after('precio_linea_extra');
            $table->decimal('precio_revendedor_extra', 12)->default(10)->after('revendedores_incluidos');
        });

        Schema::table('suscripciones', function (Blueprint $table) {
            $table->unsignedInteger('revendedores_incluidos_contratados')->default(25)->after('lineas_extra_contratadas');
            $table->decimal('precio_revendedor_extra_contratado', 12)->default(10)->after('revendedores_incluidos_contratados');
            $table->unsignedInteger('revendedores_extra_contratados')->default(0)->after('precio_revendedor_extra_contratado');
        });

        $limites = [
            'pro' => [50, 10],
            'enterprise' => [100, 10],
        ];

        foreach (DB::table('planes_suscripcion')->get(['id', 'nombre']) as $plan) {
            $clave = mb_strtolower(trim((string) $plan->nombre));

            // «Prueba 5» y cualquier otro plan de prueba de cobros.
            $valores = $limites[$clave] ?? (str_starts_with($clave, 'prueba') ? [5, 1] : null);

            if ($valores !== null) {
                [$incluidos, $precio] = $valores;

                DB::table('planes_suscripcion')->where('id', $plan->id)->update([
                    'revendedores_incluidos' => $incluidos,
                    'precio_revendedor_extra' => $precio,
                ]);
            }
        }

        // Cada suscripción toma los números de su plan, como hoy las líneas.
        foreach (DB::table('planes_suscripcion')->get(['id', 'revendedores_incluidos', 'precio_revendedor_extra']) as $plan) {
            DB::table('suscripciones')->where('plan_id', $plan->id)->update([
                'revendedores_incluidos_contratados' => $plan->revendedores_incluidos,
                'precio_revendedor_extra_contratado' => $plan->precio_revendedor_extra,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropColumn([
                'revendedores_incluidos_contratados',
                'precio_revendedor_extra_contratado',
                'revendedores_extra_contratados',
            ]);
        });

        Schema::table('planes_suscripcion', function (Blueprint $table) {
            $table->dropColumn(['revendedores_incluidos', 'precio_revendedor_extra']);
        });
    }
};
