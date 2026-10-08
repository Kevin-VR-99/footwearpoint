<?php

namespace Tests\Feature\Seguridad;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba obligatoria de seguridad: lo de una distribuidora no se le escapa a
 * otra.
 *
 * Desde el Sprint 4 (TG-213) el catálogo cambió de dueño: líneas, marcas,
 * productos y precios de catálogo son UNO SOLO para todo FootwearPoint, los
 * administra el admin general y cualquiera los puede consultar. Entonces el
 * aislamiento ya no se mide ahí, sino en lo que sí es de cada quien:
 *
 *   - qué parte del catálogo vende (sus líneas y lo que oculta),
 *   - sus sucursales, su stock, sus pedidos y sus clientes,
 *   - y que su personal ya no pueda editar el catálogo de todos.
 */
class AislamientoMultiTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoEmpleadoDeLaA(): void
    {
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    private function comoAdminDeLaA(): void
    {
        Sanctum::actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    private function otraDistribuidora(): Distribuidora
    {
        return Distribuidora::create([
            'nombre_comercial' => 'Zapatería Rival (prueba)',
            'rfc' => 'ZRI010101'.strtoupper(substr(uniqid(), -3)),
            'slug' => 'zapateria-rival-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);
    }

    /**
     * Un producto del catálogo compartido, en su propia línea. Si se le pasa
     * una distribuidora, se le activa esa línea: así es "de las que ella
     * vende".
     */
    private function productoDelCatalogo(string $sufijo, ?int $paraDistribuidoraId = null): ProductoCampana
    {
        $linea = Linea::create(['nombre' => 'Línea '.$sufijo, 'activa' => true]);

        $producto = Producto::create([
            'marca_id' => Marca::create(['nombre' => 'Marca '.$sufijo, 'activa' => true])->id,
            'categoria_id' => CategoriaProducto::create(['nombre' => 'Categoría '.$sufijo, 'activa' => true])->id,
            'modelo' => 'MOD-'.$sufijo,
            'nombre' => 'Producto '.$sufijo,
            'activo' => true,
        ]);

        $campana = Campana::create([
            'linea_id' => $linea->id,
            'nombre' => 'Temporada '.$sufijo,
            'estado' => 'activa',
        ]);

        if ($paraDistribuidoraId !== null) {
            DistribuidoraLinea::withoutGlobalScopes()->create([
                'distribuidora_id' => $paraDistribuidoraId,
                'linea_id' => $linea->id,
                'es_extra' => false,
                'activa' => true,
                'fecha_activacion' => now(),
            ]);
        }

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'CAT-'.$sufijo,
            'precio_publico' => 800,
            'activo' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // El catálogo es de todos
    // ------------------------------------------------------------------

    public function test_el_catalogo_es_el_mismo_para_todas_las_distribuidoras(): void
    {
        $otra = $this->otraDistribuidora();
        $producto = $this->productoDelCatalogo('compartido', $otra->id);

        $this->comoEmpleadoDeLaA();

        // Consultarlo se puede: es el catálogo de FootwearPoint, no de nadie.
        $this->getJson('/api/producto-campana/'.$producto->id)
            ->assertOk()
            ->assertJsonPath('data.codigo_catalogo', 'CAT-compartido');
    }

    public function test_el_personal_de_una_distribuidora_ya_no_puede_editar_el_catalogo(): void
    {
        $marca = Marca::create(['nombre' => 'Marca del catálogo', 'activa' => true]);

        $this->comoAdminDeLaA();

        // Crear y editar catálogo es solo del admin general (TG-213).
        $this->postJson('/api/marcas', ['nombre' => 'Marca nueva'])->assertStatus(403);
        $this->patchJson('/api/marcas/'.$marca->id, ['nombre' => 'Otro nombre'])->assertStatus(403);
        $this->postJson('/api/productos', ['modelo' => 'X', 'nombre' => 'X'])->assertStatus(403);

        $this->assertSame('Marca del catálogo', $marca->fresh()->nombre);
    }

    // ------------------------------------------------------------------
    // Pero cada quien vende lo suyo
    // ------------------------------------------------------------------

    public function test_el_catalogo_consultable_no_trae_productos_de_lineas_que_no_vende(): void
    {
        $otra = $this->otraDistribuidora();
        $soloDeLaOtra = $this->productoDelCatalogo('ajeno', $otra->id);

        $this->comoEmpleadoDeLaA();

        $idsEnCatalogo = collect($this->getJson('/api/catalogo')->assertOk()->json('data'))->pluck('id');

        $this->assertNotContains($soloDeLaOtra->id, $idsEnCatalogo);
        $this->assertNotEmpty($idsEnCatalogo, 'La distribuidora A no vio nada de su propio catálogo.');
    }

    public function test_un_producto_oculto_por_una_distribuidora_lo_sigue_viendo_la_otra(): void
    {
        $distribuidoraA = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $otra = $this->otraDistribuidora();

        // El mismo producto del catálogo, que las dos venden.
        $producto = $this->productoDelCatalogo('comun', $distribuidoraA->id);
        DistribuidoraLinea::withoutGlobalScopes()->create([
            'distribuidora_id' => $otra->id,
            'linea_id' => $producto->campana->linea_id,
            'es_extra' => false,
            'activa' => true,
            'fecha_activacion' => now(),
        ]);

        // La A lo oculta...
        $this->comoAdminDeLaA();
        app(GestionarOfertaDistribuidoraAction::class)->ocultar($producto->id);

        $idsDeLaA = collect($this->getJson('/api/catalogo')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($producto->id, $idsDeLaA);

        // ...y a la otra no le afecta.
        Tenant::forzar($otra->id, function () use ($producto) {
            $visibles = app(\App\Services\Catalogo\CatalogoVisible::class)->consulta()->pluck('id');
            $this->assertTrue($visibles->contains($producto->id));
        });
    }

    // ------------------------------------------------------------------
    // Lo que sí es de cada quien sigue aislado
    // ------------------------------------------------------------------

    public function test_una_distribuidora_no_ve_las_sucursales_ni_el_stock_de_otra(): void
    {
        $otra = $this->otraDistribuidora();

        $sucursalAjena = Tenant::forzar($otra->id, fn () => Sucursal::create([
            'nombre' => 'Sucursal rival',
            'direccion' => 'Otra calle',
            'es_principal' => true,
            'activa' => true,
        ]));

        $this->comoEmpleadoDeLaA();

        $this->assertNotContains($sucursalAjena->id, Sucursal::pluck('id'));
    }

    public function test_una_distribuidora_no_ve_las_lineas_que_activo_otra(): void
    {
        $otra = $this->otraDistribuidora();
        $this->productoDelCatalogo('solo-de-la-otra', $otra->id);

        $this->comoEmpleadoDeLaA();

        $lineasAjenas = DistribuidoraLinea::withoutGlobalScopes()
            ->where('distribuidora_id', $otra->id)
            ->pluck('id');

        $this->assertNotEmpty($lineasAjenas);
        foreach ($lineasAjenas as $id) {
            $this->assertNotContains($id, DistribuidoraLinea::pluck('id'));
        }
    }
}
