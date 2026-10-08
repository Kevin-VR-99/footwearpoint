<?php

namespace Database\Seeders;

use Database\Seeders\Support\PasswordDePrueba;
use Illuminate\Database\Seeder;

/**
 * Deja la base lista para trabajar y para la demo (TG-186).
 *
 * Con `php artisan migrate:fresh --seed` queda todo: el admin general, el
 * catálogo maestro con datos de catálogos reales, dos distribuidoras de
 * prueba con su personal y lo que cada una vende, y los contactos de la
 * primera, dos de ellos con cuenta en la app.
 *
 * Todas las cuentas de prueba usan la misma contraseña, que sale de
 * SEED_PASSWORD (ver Support\PasswordDePrueba).
 */
class DatabaseSeeder extends Seeder
{
    /** Las cuentas que deja listas, con el rol que tiene cada una. */
    public const CUENTAS = [
        AdminGeneralSeeder::EMAIL => 'admin_general',
        DemoDistribuidoraSeeder::ADMIN => 'admin_distribuidora',
        DemoDistribuidoraSeeder::EMPLEADO => 'empleado',
        DemoDistribuidoraSeeder::SEGUNDA_ADMIN => 'admin_distribuidora',
        DemoContactosSeeder::REVENDEDOR => 'revendedor',
        DemoContactosSeeder::CLIENTE_DIRECTO => 'cliente_directo',
    ];

    public function run(): void
    {
        $this->call([
            TallaSeeder::class,
            ColorSeeder::class,
            PlanSuscripcionSeeder::class,
            RolesPermissionsSeeder::class,
            AdminGeneralSeeder::class,
            DemoDistribuidoraSeeder::class,
            CatalogoMaestroDemoSeeder::class,
            DemoCatalogoDistribuidorasSeeder::class,
            DemoContactosSeeder::class,
            CategoriaDirectorioSeeder::class,
        ]);

        $this->resumen();
    }

    /** La lista de cuentas al final, para no andarla buscando en el código. */
    private function resumen(): void
    {
        $this->command?->newLine();
        $this->command?->info('Cuentas de prueba listas:');

        foreach (self::CUENTAS as $correo => $rol) {
            $this->command?->info(sprintf('  %-38s %s', $correo, $rol));
        }

        // Nunca se imprime la contraseña si viene de SEED_PASSWORD.
        $this->command?->info('Todas entran con '.PasswordDePrueba::comoExplicarla().'.');
    }
}
