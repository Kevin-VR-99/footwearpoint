<?php

namespace Tests\Feature\Sprint4;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Variante;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TG-209 (Ola 2, K2) — El catálogo es uno solo y compartido.
 *
 * Líneas, marcas, categorías, temporadas, productos, variantes, precios,
 * disponibilidad e imágenes existen UNA vez en todo el sistema y solo los
 * administra el admin general (sección 4.1 del diseño).
 */
class CatalogoMaestroTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const TABLAS_DEL_CATALOGO = [
        'lineas', 'marcas', 'linea_marca', 'categorias_producto', 'campanas',
        'productos', 'variantes', 'producto_campana',
        'disponibilidad_variante_campana', 'producto_imagenes',
    ];

    private function nuevaLinea(string $nombre): Linea
    {
        return Linea::create(['nombre' => $nombre, 'activa' => true]);
    }

    private function nuevoProducto(string $modelo): Producto
    {
        return Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => $modelo,
            'nombre' => 'Producto de prueba',
            'activo' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // El catálogo ya no es de nadie en particular
    // ------------------------------------------------------------------

    public function test_ninguna_tabla_del_catalogo_tiene_distribuidora(): void
    {
        foreach (self::TABLAS_DEL_CATALOGO as $tabla) {
            $this->assertFalse(
                Schema::hasColumn($tabla, 'distribuidora_id'),
                "La tabla $tabla todavía tiene distribuidora_id"
            );
        }
    }

    /** El stock y los pedidos sí siguen siendo de cada distribuidora (4.3). */
    public function test_la_operacion_sigue_siendo_de_cada_distribuidora(): void
    {
        foreach (['stock_local', 'pedido_detalle', 'venta_directa_detalle', 'productos_destacados'] as $tabla) {
            $this->assertTrue(Schema::hasColumn($tabla, 'distribuidora_id'), "$tabla perdió su distribuidora_id");
        }
    }

    // ------------------------------------------------------------------
    // Sin duplicados
    // ------------------------------------------------------------------

    public function test_no_puede_haber_dos_lineas_con_el_mismo_nombre(): void
    {
        $this->nuevaLinea('Impuls');

        $this->expectException(QueryException::class);
        $this->nuevaLinea('Impuls');
    }

    public function test_no_puede_haber_dos_marcas_ni_dos_categorias_con_el_mismo_nombre(): void
    {
        Marca::create(['nombre' => 'Cklass', 'activa' => true]);
        CategoriaProducto::create(['nombre' => 'Botas', 'activa' => true]);

        try {
            Marca::create(['nombre' => 'Cklass', 'activa' => true]);
            $this->fail('Se creó una marca repetida.');
        } catch (QueryException) {
            // Esperado.
        }

        $this->expectException(QueryException::class);
        CategoriaProducto::create(['nombre' => 'Botas', 'activa' => true]);
    }

    public function test_un_modelo_no_se_repite_dentro_de_la_misma_marca(): void
    {
        $this->nuevoProducto('DH3158');

        $this->expectException(QueryException::class);
        $this->nuevoProducto('DH3158');
    }

    public function test_el_sku_es_unico_en_todo_el_sistema(): void
    {
        $talla = Talla::firstOrFail();
        $color = Color::firstOrFail();

        Variante::create([
            'producto_id' => $this->nuevoProducto('MOD-A')->id,
            'talla_id' => $talla->id,
            'color_id' => $color->id,
            'sku' => 'SKU-UNICO',
            'activa' => true,
        ]);

        $this->expectException(QueryException::class);
        Variante::create([
            'producto_id' => $this->nuevoProducto('MOD-B')->id,
            'talla_id' => $talla->id,
            'color_id' => $color->id,
            'sku' => 'SKU-UNICO',
            'activa' => true,
        ]);
    }

    public function test_el_codigo_de_catalogo_no_se_repite_dentro_de_la_temporada(): void
    {
        $campana = Campana::firstOrFail();

        $this->expectException(QueryException::class);
        ProductoCampana::create([
            'producto_id' => $this->nuevoProducto('MOD-NUEVO')->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => ProductoCampana::firstOrFail()->codigo_catalogo,
            'precio_publico' => 999,
            'activo' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // La temporada pertenece a una línea (D1) y solo una está activa (D7)
    // ------------------------------------------------------------------

    public function test_la_temporada_pertenece_a_una_linea(): void
    {
        $campana = Campana::with('linea')->firstOrFail();

        $this->assertNotNull($campana->linea);
        $this->assertTrue($campana->linea->campanas->contains($campana));
        $this->assertSame($campana->id, $campana->linea->campanaActiva->id);
    }

    public function test_una_linea_no_puede_tener_dos_temporadas_activas(): void
    {
        $linea = Campana::firstOrFail()->linea;

        try {
            Campana::create([
                'linea_id' => $linea->id,
                'nombre' => 'Otra temporada',
                'estado' => 'activa',
            ]);
            $this->fail('Se activaron dos temporadas en la misma línea.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Esta línea ya tiene una temporada activa. Cierra la anterior antes de activar otra.',
                $e->errors()['estado'][0]
            );
        }

        $this->assertSame(1, Campana::where('linea_id', $linea->id)->where('estado', 'activa')->count());
    }

    public function test_otra_linea_si_puede_tener_su_propia_temporada_activa(): void
    {
        $otraLinea = $this->nuevaLinea('Confort');

        $campana = Campana::create([
            'linea_id' => $otraLinea->id,
            'nombre' => 'Confort Otoño-Invierno 2026',
            'estado' => 'activa',
        ]);

        $this->assertSame('activa', $campana->fresh()->estado);
    }

    /** Ni cambiando el estado por fuera del modelo: la base también lo impide. */
    public function test_la_regla_tambien_la_cuida_la_base_de_datos(): void
    {
        $activa = Campana::where('estado', 'activa')->firstOrFail();

        $borrador = Campana::create([
            'linea_id' => $activa->linea_id,
            'nombre' => 'Temporada en borrador',
            'estado' => 'borrador',
        ]);

        $this->expectException(QueryException::class);
        DB::table('campanas')->where('id', $borrador->id)->update(['estado' => 'activa']);
    }

    // ------------------------------------------------------------------
    // Precios del catálogo
    // ------------------------------------------------------------------

    public function test_el_producto_de_temporada_guarda_un_solo_precio_el_de_menudeo(): void
    {
        $productoCampana = ProductoCampana::firstOrFail();

        $this->assertTrue(Schema::hasColumn('producto_campana', 'precio_publico'));
        $this->assertGreaterThan(0, (float) $productoCampana->precio_publico);
        $this->assertTrue($productoCampana->activo);

        foreach (['precio_mayorista', 'precio_minorista_sugerido', 'publicado', 'estado_disponibilidad'] as $columna) {
            $this->assertFalse(
                Schema::hasColumn('producto_campana', $columna),
                "producto_campana todavía tiene $columna"
            );
        }
    }

    /** El producto ya no se amarra a una línea: se sabe por su temporada (D5). */
    public function test_el_producto_ya_no_tiene_linea_propia(): void
    {
        $this->assertFalse(Schema::hasColumn('productos', 'linea_id'));

        $productoCampana = ProductoCampana::with('campana.linea')->firstOrFail();
        $this->assertNotNull($productoCampana->campana->linea->nombre);
    }
}
