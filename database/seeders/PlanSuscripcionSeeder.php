<?php

namespace Database\Seeders;

use App\Models\PlanSuscripcion;
use Illuminate\Database\Seeder;

class PlanSuscripcionSeeder extends Seeder
{
    public function run(): void
    {
        $planes = [
            [
                'nombre' => 'Básico',
                'descripcion' => 'Ideal para distribuidoras pequeñas o que recién empiezan.',
                'precio_base_mensual' => 299.00,
                'lineas_incluidas' => 2,
                'precio_linea_extra' => 150.00,
            ],
            [
                'nombre' => 'Pro',
                'descripcion' => 'Recomendado para distribuidoras medianas.',
                'precio_base_mensual' => 599.00,
                'lineas_incluidas' => 5,
                'precio_linea_extra' => 150.00,
            ],
            [
                'nombre' => 'Enterprise',
                'descripcion' => 'Para distribuidoras grandes o con muchas marcas.',
                'precio_base_mensual' => 999.00,
                'lineas_incluidas' => 10,
                'precio_linea_extra' => 150.00,
            ],
        ];

        foreach ($planes as $plan) {
            PlanSuscripcion::firstOrCreate(
                ['nombre' => $plan['nombre']],
                $plan + ['activo' => true]
            );
        }
    }
}