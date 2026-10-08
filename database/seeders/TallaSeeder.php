<?php

namespace Database\Seeders;

use App\Models\Talla;
use Illuminate\Database\Seeder;

class TallaSeeder extends Seeder
{
    public function run(): void
    {
        // Tallas de calzado más comunes en México (sistema MX).
        // Niño y caballero van del 22 al 30; dama, del 2 al 7 (TG-217).
        $valores = [
            '2', '3', '4', '5', '6', '7',
            '22', '22.5', '23', '23.5', '24', '24.5', '25', '25.5',
            '26', '26.5', '27', '27.5', '28', '29', '30',
        ];

        foreach ($valores as $valor) {
            Talla::firstOrCreate(
                ['sistema' => 'MX', 'valor' => $valor],
                ['orden' => (float) $valor, 'activa' => true]
            );
        }
    }
}
