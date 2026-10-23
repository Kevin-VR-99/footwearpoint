<?php

namespace Database\Seeders\Support;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Candado: los seeders de demostración (distribuidoras, catálogo, contactos)
 * NO corren en producción. Usan firstOrCreate, así que un `db:seed` en
 * producción volvería a crear el catálogo demo que se borró.
 *
 * Solo se saltan si la app está en `production`; en local y en pruebas corren
 * igual que siempre. Para forzarlos a propósito: SEED_DEMO_EN_PRODUCCION=true.
 * Ver docs/railway-produccion.md ("db:seed nunca se corre en producción").
 */
final class DemoEnProduccion
{
    public static function bloqueada(Seeder $seeder, ?Command $command = null): bool
    {
        if (! app()->environment('production') || config('app.seed_demo_en_produccion')) {
            return false;
        }

        $command?->warn(class_basename($seeder).' se saltó: los datos de demostración no se siembran en producción (SEED_DEMO_EN_PRODUCCION=true para forzarlo).');

        return true;
    }
}
