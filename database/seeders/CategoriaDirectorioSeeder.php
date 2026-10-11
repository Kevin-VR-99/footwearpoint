<?php

namespace Database\Seeders;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use Illuminate\Database\Seeder;

/**
 * TG-197 (G16) — Categorías generales del directorio, de ejemplo.
 *
 * Son datos de demostración: el admin general puede renombrarlas,
 * desactivarlas o crear otras desde "Categorías del directorio". Se puede
 * correr varias veces sin duplicar nada:
 *
 *   php artisan db:seed --class=CategoriaDirectorioSeeder
 */
class CategoriaDirectorioSeeder extends Seeder
{
    public const CATEGORIAS = ['Dama', 'Caballero', 'Infantil', 'Deportivo', 'Escolar', 'Casual'];

    /** Las que se le asignan a la distribuidora demo. */
    public const DEMO_CALZADOS_RAMIREZ = ['Dama', 'Caballero', 'Casual'];

    public function run(): void
    {
        foreach (self::CATEGORIAS as $nombre) {
            CategoriaDirectorio::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
        }

        $demo = Distribuidora::where('slug', 'calzados-ramirez')->first();

        if ($demo) {
            $ids = CategoriaDirectorio::whereIn('nombre', self::DEMO_CALZADOS_RAMIREZ)->pluck('id')->all();
            $demo->categoriasDirectorio()->syncWithoutDetaching($ids);
        }
    }
}
