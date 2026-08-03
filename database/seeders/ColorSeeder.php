<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    public function run(): void
    {
        $colores = [
            ['nombre' => 'Negro', 'codigo_hex' => '#000000'],
            ['nombre' => 'Blanco', 'codigo_hex' => '#FFFFFF'],
            ['nombre' => 'Azul', 'codigo_hex' => '#1E3A8A'],
            ['nombre' => 'Rojo', 'codigo_hex' => '#B91C1C'],
            ['nombre' => 'Gris', 'codigo_hex' => '#6B7280'],
            ['nombre' => 'Café', 'codigo_hex' => '#78350F'],
            ['nombre' => 'Beige', 'codigo_hex' => '#E7D9C4'],
            ['nombre' => 'Verde', 'codigo_hex' => '#166534'],
        ];

        foreach ($colores as $color) {
            Color::firstOrCreate(
                ['nombre' => $color['nombre']],
                ['codigo_hex' => $color['codigo_hex'], 'activo' => true]
            );
        }
    }
}