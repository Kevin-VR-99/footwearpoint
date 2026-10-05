<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\Distribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\Variante;
use Illuminate\Database\Seeder;

/**
 * Catálogo de prueba, ya con el catálogo compartido (TG-209).
 *
 * El catálogo (línea, temporada, marcas, productos y disponibilidad) existe
 * una sola vez para todo el sistema; lo único que sigue siendo de la
 * distribuidora demo es su stock.
 *
 * Es el mínimo para que migrate:fresh --seed siga funcionando y las pruebas
 * tengan con qué trabajar. El catálogo realista, con datos de catálogos de
 * verdad, es la tarea K11.
 */
class DemoCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $sucursal = Sucursal::where('distribuidora_id', $distribuidora->id)->where('es_principal', true)->firstOrFail();

        $linea = Linea::firstOrCreate(['nombre' => 'Línea Demo'], ['activa' => true]);

        $marcas = [];
        foreach (['Nike', 'Adidas', 'Flexi'] as $nombreMarca) {
            $marca = Marca::firstOrCreate(['nombre' => $nombreMarca], ['activa' => true]);
            $linea->marcas()->syncWithoutDetaching([$marca->id]);
            $marcas[] = $marca;
        }

        $categoria = CategoriaProducto::firstOrCreate(['nombre' => 'Calzado deportivo'], ['activa' => true]);

        // Solo una temporada activa por línea (D7).
        $campana = Campana::firstOrCreate(
            ['linea_id' => $linea->id, 'nombre' => 'Temporada Demo 2026'],
            [
                'fecha_inicio' => now()->subDays(10),
                'fecha_fin' => now()->addMonths(3),
                'estado' => 'activa',
            ]
        );

        $tallasDisponibles = Talla::where('sistema', 'MX')->whereIn('valor', ['25', '26', '27'])->get();
        $colorNegro = Color::where('nombre', 'Negro')->firstOrFail();
        $colorBlanco = Color::where('nombre', 'Blanco')->firstOrFail();

        $nombresProductos = [
            'Urban Runner', 'Classic Leather', 'Trail Max', 'Elegance Heel', 'Air Comfort',
            'Street Style', 'Casual Walk', 'Sport Flex', 'Retro Court', 'Daily Wear',
        ];

        foreach ($nombresProductos as $i => $nombreProducto) {
            $marca = $marcas[$i % 3];

            $producto = Producto::firstOrCreate(
                [
                    'marca_id' => $marca->id,
                    'modelo' => 'MOD-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                ],
                [
                    'categoria_id' => $categoria->id,
                    'nombre' => $nombreProducto,
                    'descripcion' => 'Producto de prueba para el catálogo demo.',
                    'activo' => true,
                ]
            );

            // Un solo precio, el de menudeo: es lo que traen los catálogos de
            // fábrica (D8). El mayoreo lo pone cada distribuidora.
            $productoCampana = ProductoCampana::firstOrCreate(
                [
                    'producto_id' => $producto->id,
                    'campana_id' => $campana->id,
                ],
                [
                    'codigo_catalogo' => 'ZP-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                    'precio_publico' => round((650 + ($i * 20)) * 1.55, 2),
                    'activo' => true,
                ]
            );

            foreach ($tallasDisponibles->take(2) as $talla) {
                $variante = Variante::firstOrCreate(
                    [
                        'producto_id' => $producto->id,
                        'talla_id' => $talla->id,
                        'color_id' => $i % 2 === 0 ? $colorNegro->id : $colorBlanco->id,
                    ],
                    [
                        'sku' => 'SKU-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT).'-'.$talla->valor,
                        'activa' => true,
                    ]
                );

                DisponibilidadVarianteCampana::firstOrCreate(
                    [
                        'producto_campana_id' => $productoCampana->id,
                        'variante_id' => $variante->id,
                    ],
                    [
                        'estado' => 'disponible',
                        'fecha_verificacion' => now(),
                    ]
                );

                // El stock sí es de la distribuidora y su sucursal.
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

        $this->command->info('Catálogo compartido demo: 1 línea, 1 temporada activa, 3 marcas y 10 productos con variantes; stock para la distribuidora demo.');
    }
}
