<?php

namespace Database\Seeders;

use App\Models\Usuario;
use App\Support\DocumentosLegales;
use Illuminate\Database\Seeder;

/**
 * TG-323 (K24) — Las cuentas de prueba ya aceptaron la versión vigente de los
 * documentos legales.
 *
 * Desde K24 el panel y la API piden aceptar la versión vigente antes de
 * seguir. Sin esto, cada cuenta de prueba caería primero en /legales y las
 * pruebas de cada pantalla tendrían que aceptarlos antes. Las pruebas de
 * ReaceptacionLegalesTest quitan estas filas para revisar el caso pendiente.
 *
 * Solo corre con `migrate:fresh --seed` en local y en las pruebas: db:seed
 * nunca se corre en producción (docs/railway-produccion.md).
 */
class AceptacionesLegalesDePruebaSeeder extends Seeder
{
    public function run(): void
    {
        Usuario::query()->each(function (Usuario $usuario) {
            foreach (DocumentosLegales::tipos() as $tipo) {
                DocumentosLegales::registrar($usuario, $tipo, null);
            }
        });
    }
}
