<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Talla;
use App\Models\Variante;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * El catálogo maestro de la demo, con datos de catálogos reales (TG-217).
 *
 * Es UNO SOLO para todo FootwearPoint y lo administra el admin general: aquí
 * no hay ninguna distribuidora. Lo que cada distribuidora hace con él (qué
 * líneas vende, su precio de mayoreo, qué oculta) va en sus propios seeders.
 *
 * Los datos salen de database/seeders/datos/catalogo_demo.php.
 *
 * Las fotos solo se suben al almacenamiento si SEED_IMAGENES=true: en las
 * pruebas nunca, para no depender de la red ni tardar de más.
 */
class CatalogoMaestroDemoSeeder extends Seeder
{
    public function run(): void
    {
        $catalogo = require database_path('seeders/datos/catalogo_demo.php');

        $subirImagenes = filter_var(env('SEED_IMAGENES', false), FILTER_VALIDATE_BOOL);

        $productos = 0;
        $temporadas = 0;

        foreach ($catalogo as $datosLinea) {
            $linea = Linea::firstOrCreate(
                ['nombre' => $datosLinea['linea']],
                ['descripcion' => $datosLinea['descripcion'], 'activa' => true]
            );

            foreach ($datosLinea['marcas'] as $nombreMarca) {
                $marca = Marca::firstOrCreate(['nombre' => $nombreMarca], ['activa' => true]);
                $linea->marcas()->syncWithoutDetaching([$marca->id]);
            }

            foreach ($datosLinea['temporadas'] as $datosTemporada) {
                $campana = $this->temporada($linea, $datosTemporada);
                $temporadas++;

                foreach ($datosTemporada['productos'] as $datosProducto) {
                    $this->producto($campana, $datosProducto, $subirImagenes);
                    $productos++;
                }
            }
        }

        $this->command?->info(
            "Catálogo maestro: ".count($catalogo)." líneas, {$temporadas} temporadas y {$productos} productos."
            .($subirImagenes ? ' Con fotos.' : ' Sin fotos (SEED_IMAGENES).')
        );
    }

    /** Solo una temporada activa por línea; el modelo Campana lo vigila (D7). */
    private function temporada(Linea $linea, array $datos): Campana
    {
        return Campana::firstOrCreate(
            ['linea_id' => $linea->id, 'nombre' => $datos['nombre']],
            [
                'fecha_inicio' => now()->modify($datos['inicio']),
                'fecha_fin' => now()->modify($datos['fin']),
                'estado' => $datos['estado'],
            ]
        );
    }

    private function producto(Campana $campana, array $datos, bool $subirImagenes): void
    {
        $marca = Marca::where('nombre', $datos['marca'])->firstOrFail();
        $categoria = CategoriaProducto::firstOrCreate(['nombre' => $datos['categoria']], ['activa' => true]);

        // Un producto es un modelo EN UN COLOR (D9): por eso el modelo del
        // catálogo ya trae el color cuando la fábrica repite el número.
        $producto = Producto::firstOrCreate(
            ['marca_id' => $marca->id, 'modelo' => $datos['modelo']],
            [
                'categoria_id' => $categoria->id,
                'nombre' => $datos['nombre'].' '.$datos['color_comercial'],
                'descripcion' => null,
                'activo' => true,
            ]
        );

        $productoCampana = ProductoCampana::firstOrCreate(
            ['producto_id' => $producto->id, 'campana_id' => $campana->id],
            [
                'codigo_catalogo' => $datos['codigo'],
                'precio_publico' => $datos['precio'],
                'activo' => true,
            ]
        );

        $color = Color::where('nombre', $datos['color'])->firstOrFail();

        foreach ($datos['tallas'] as $valorTalla) {
            $talla = Talla::where('sistema', 'MX')->where('valor', $valorTalla)->firstOrFail();

            $variante = Variante::firstOrCreate(
                [
                    'producto_id' => $producto->id,
                    'talla_id' => $talla->id,
                    'color_id' => $color->id,
                ],
                [
                    'nombre_color_comercial' => $datos['color_comercial'],
                    'sku' => $datos['modelo'].'-'.$valorTalla,
                    'activa' => true,
                ]
            );

            DisponibilidadVarianteCampana::firstOrCreate(
                ['producto_campana_id' => $productoCampana->id, 'variante_id' => $variante->id],
                [
                    'estado' => $this->disponibilidad($datos, $valorTalla),
                    'fecha_verificacion' => now(),
                ]
            );
        }

        if ($subirImagenes && isset($datos['imagen'])) {
            $this->foto($productoCampana, $datos['imagen']);
        }
    }

    /** Casi todo está disponible; algunas tallas no, para que se note la regla. */
    private function disponibilidad(array $datos, string $talla): string
    {
        if (in_array($talla, $datos['no_disponible'] ?? [], true)) {
            return 'no_disponible';
        }

        if (in_array($talla, $datos['bajo_pedido'] ?? [], true)) {
            return 'bajo_pedido';
        }

        return 'disponible';
    }

    private function foto(ProductoCampana $productoCampana, string $archivo): void
    {
        if ($productoCampana->imagenes()->exists()) {
            return;
        }

        $origen = database_path('seeders/imagenes/'.$archivo);

        if (! is_file($origen)) {
            return;
        }

        $ruta = 'productos/campana/imagenes/'.$archivo;
        Storage::disk('s3')->put($ruta, file_get_contents($origen));

        ProductoImagen::create([
            'producto_campana_id' => $productoCampana->id,
            'url' => Storage::disk('s3')->url($ruta),
            'orden' => 1,
            'es_principal' => true,
        ]);
    }
}
