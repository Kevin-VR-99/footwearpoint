<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\Distribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\Variante;
use Illuminate\Database\Seeder;

class DemoCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $sucursal = Sucursal::where('distribuidora_id', $distribuidora->id)->where('es_principal', true)->firstOrFail();

        // --- Marcas ---
        $marcas = [];
        foreach (['Nike', 'Adidas', 'Flexi'] as $nombreMarca) {
            $marcas[] = Marca::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'nombre' => $nombreMarca],
                ['activa' => true]
            );
        }

        // --- Categoría ---
        $categoria = CategoriaProducto::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'nombre' => 'Calzado deportivo'],
            ['activa' => true]
        );

        // --- Campaña activa ---
        $campana = Campana::firstOrCreate(
            ['distribuidora_id' => $distribuidora->id, 'marca_id' => $marcas[0]->id, 'nombre' => 'Temporada Demo 2026'],
            [
                'fecha_inicio' => now()->subDays(10),
                'fecha_fin' => now()->addMonths(3),
                'estado' => 'activa',
            ]
        );

        $tallasDisponibles = Talla::where('sistema', 'MX')->whereIn('valor', ['25', '26', '27'])->get();
        $colorNegro = Color::where('nombre', 'Negro')->firstOrFail();
        $colorBlanco = Color::where('nombre', 'Blanco')->firstOrFail();

        // --- 10 productos de prueba, repartidos entre las 3 marcas ---
        $nombresProductos = [
            'Urban Runner', 'Classic Leather', 'Trail Max', 'Elegance Heel', 'Air Comfort',
            'Street Style', 'Casual Walk', 'Sport Flex', 'Retro Court', 'Daily Wear',
        ];

        foreach ($nombresProductos as $i => $nombreProducto) {
            $marca = $marcas[$i % 3];

            $producto = Producto::firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'marca_id' => $marca->id,
                    'modelo' => 'MOD-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                ],
                [
                    'categoria_id' => $categoria->id,
                    'nombre' => $nombreProducto,
                    'descripcion' => 'Producto de prueba para el catálogo demo.',
                    'activo' => true,
                ]
            );

            $precioMayorista = 650 + ($i * 20);
            $precioMinorista = round($precioMayorista * 1.55, 2);

            $productoCampana = ProductoCampana::firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'producto_id' => $producto->id,
                    'campana_id' => $campana->id,
                ],
                [
                    'codigo_catalogo' => 'ZP-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                    'precio_mayorista' => $precioMayorista,
                    'precio_minorista_sugerido' => $precioMinorista,
                    'estado_disponibilidad' => 'disponible',
                    'publicado' => true,
                ]
            );

            foreach ($tallasDisponibles->take(2) as $talla) {
                $variante = Variante::firstOrCreate(
                    [
                        'distribuidora_id' => $distribuidora->id,
                        'producto_id' => $producto->id,
                        'talla_id' => $talla->id,
                        'color_id' => $i % 2 === 0 ? $colorNegro->id : $colorBlanco->id,
                    ],
                    [
                        'sku' => 'SKU-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT) . '-' . $talla->valor,
                        'activa' => true,
                    ]
                );

                DisponibilidadVarianteCampana::firstOrCreate(
                    [
                        'distribuidora_id' => $distribuidora->id,
                        'producto_campana_id' => $productoCampana->id,
                        'variante_id' => $variante->id,
                    ],
                    [
                        'estado' => 'disponible',
                        'fecha_verificacion' => now(),
                    ]
                );

                StockLocal::firstOrCreate(
                    [
                        'distribuidora_id' => $distribuidora->id,
                        'sucursal_id' => $sucursal->id,
                        'variante_id' => $variante->id,
                    ],
                    [
                        'cantidad_disponible' => 10,
                        'stock_minimo' => 2,
                    ]
                );
            }
        }

        $this->command->info('Catálogo demo creado: 3 marcas, 1 campaña, 10 productos con variantes y stock.');
    }
}