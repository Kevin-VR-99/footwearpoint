<?php

namespace Database\Seeders;

use App\Models\Talla;
use Illuminate\Database\Seeder;

class TallaSeeder extends Seeder
{
    public function run(): void
    {
        // Tallas de calzado más comunes en México (sistema MX), del 22 al 30.
        $valores = ['22', '22.5', '23', '23.5', '24', '24.5', '25', '25.5', '26', '26.5', '27', '27.5', '28', '29', '30'];

        foreach ($valores as $valor) {
            Talla::firstOrCreate(
                ['sistema' => 'MX', 'valor' => $valor],
                ['orden' => (float) $valor, 'activa' => true]
            );
        }
    }
}