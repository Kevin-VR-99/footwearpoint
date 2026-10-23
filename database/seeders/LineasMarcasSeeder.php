<?php

namespace Database\Seeders;

use App\Models\Linea;
use App\Models\Marca;
use Illuminate\Database\Seeder;

/**
 * Crea las líneas de catálogo y les liga sus marcas de calzado.
 *
 * No borra lo que ya había: si la línea o la marca existen, las reutiliza, y
 * una marca que ya estaba en la línea se queda. Se puede correr otra vez.
 *
 * Los nombres salen de database/seeders/datos/lineas_marcas.php.
 */
class LineasMarcasSeeder extends Seeder
{
    public function run(): void
    {
        $lineas = require database_path('seeders/datos/lineas_marcas.php');
        $ligas = 0;

        foreach ($lineas as $datos) {
            $linea = Linea::firstOrCreate(
                ['nombre' => $datos['linea']],
                ['descripcion' => $datos['descripcion'], 'activa' => true],
            );

            $ids = [];
            foreach ($datos['marcas'] as $nombre) {
                $marca = Marca::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
                $ids[] = $marca->id;
            }

            $linea->marcas()->syncWithoutDetaching($ids);
            $ligas += count($ids);
        }

        $this->command?->info(
            'Líneas y marcas: '.count($lineas).' líneas y '.$ligas.' ligas de marca.'
        );
    }
}
