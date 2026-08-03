<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TallaSeeder::class,
            ColorSeeder::class,
            PlanSuscripcionSeeder::class,
            RolesPermissionsSeeder::class,
            DemoDistribuidoraSeeder::class,
            DemoCatalogoSeeder::class,
            DemoContactosSeeder::class,
        ]);
    }
}