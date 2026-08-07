<?php

namespace Tests\Feature\Seguridad;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba obligatoria, prioridad alta (sección "Transversal — Seguridad,
 * auditoría y pruebas" del documento de tareas, aplica a los 5 paquetes):
 * un usuario de la distribuidora A intenta leer/modificar un recurso de la
 * distribuidora B — debe rechazarse (403/404), nunca exponer datos reales.
 *
 * Esta cubre los recursos del Paquete B (marcas, categorías, campañas,
 * productos, producto-campana) con una SEGUNDA distribuidora real, no con
 * un id inventado que no existe — así se prueba de verdad que el Global
 * Scope filtra, no solo que un id al azar da 404.
 */
class AislamientoMultiTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoEmpleadoDistribuidoraA(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    private function autenticarComoAdminDistribuidoraA(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    /**
     * Crea una distribuidora B completamente aparte, con su propia marca,
     * categoría, producto, campaña y producto-campana — usando
     * Tenant::forzar() (la misma utilidad que ya usan los Seeders de Fase
     * 0) para poder crear estos registros sin estar autenticado como esa
     * distribuidora.
     */
    private function crearRecursosDeOtraDistribuidora(): array
    {
        $distribuidoraB = Distribuidora::create([
            'nombre_comercial'    => 'Zapatería Rival (prueba)',
            'razon_social'        => 'Zapatería Rival S.A. de C.V.',
            'rfc'                 => 'ZRI010101' . strtoupper(substr(uniqid(), -3)),
            'slug'                => 'zapateria-rival-' . uniqid(),
            'estado'              => 'activa',
            'fecha_solicitud'     => now(),
            'fecha_aprobacion'    => now(),
        ]);

        return Tenant::forzar($distribuidoraB->id, function () use ($distribuidoraB) {
            $marca = Marca::create(['nombre' => 'Marca Rival', 'activa' => true]);
            $categoria = CategoriaProducto::create(['nombre' => 'Categoría Rival', 'activa' => true]);
            $producto = Producto::create([
                'marca_id'     => $marca->id,
                'categoria_id' => $categoria->id,
                'modelo'       => 'RIVAL-01',
                'nombre'       => 'Producto Rival',
                'activo'       => true,
            ]);
            $campana = Campana::create(['marca_id' => $marca->id, 'nombre' => 'Campaña Rival']);
            $productoCampana = ProductoCampana::create([
                'producto_id'               => $producto->id,
                'campana_id'                => $campana->id,
                'codigo_catalogo'           => 'RIVAL-CAT-01',
                'precio_mayorista'          => 500,
                'precio_minorista_sugerido' => 800,
            ]);

            return compact('distribuidoraB', 'marca', 'categoria', 'producto', 'campana', 'productoCampana');
        });
    }

    public function test_no_se_puede_leer_una_marca_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->getJson("/api/marcas/{$recursos['marca']->id}")->assertStatus(404);
    }

    public function test_no_se_puede_editar_una_marca_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->patchJson("/api/marcas/{$recursos['marca']->id}", ['nombre' => 'Nombre Hackeado'])
            ->assertStatus(404);

        $this->assertDatabaseHas('marcas', ['id' => $recursos['marca']->id, 'nombre' => 'Marca Rival']);
        $this->assertDatabaseMissing('marcas', ['nombre' => 'Nombre Hackeado']);
    }

    public function test_no_se_puede_leer_un_producto_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->getJson("/api/productos/{$recursos['producto']->id}")->assertStatus(404);
    }

    public function test_no_se_puede_editar_un_producto_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->patchJson("/api/productos/{$recursos['producto']->id}", ['nombre' => 'Producto Hackeado'])
            ->assertStatus(404);
    }

    public function test_no_se_puede_leer_una_campana_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->getJson("/api/campanas/{$recursos['campana']->id}")->assertStatus(404);
    }

    public function test_no_se_puede_avanzar_el_estado_de_una_campana_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->patchJson("/api/campanas/{$recursos['campana']->id}", ['estado' => 'en_importacion'])
            ->assertStatus(404);

        $this->assertDatabaseHas('campanas', ['id' => $recursos['campana']->id, 'estado' => 'borrador']);
    }

    public function test_no_se_puede_leer_una_publicacion_producto_campana_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoEmpleadoDistribuidoraA();

        $this->getJson("/api/producto-campana/{$recursos['productoCampana']->id}")->assertStatus(404);
    }

    /**
     * Prueba de "control": confirma que el catálogo consultable de la
     * distribuidora A (que sí puede tener publicaciones reales) NUNCA
     * mezcla productos de la distribuidora B, aunque ambas existan al
     * mismo tiempo en la misma base de datos compartida.
     */
    public function test_el_catalogo_consultable_nunca_mezcla_productos_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();

        // La publicación de la distribuidora B se marca publicada y su
        // campaña se avanza a 'activa' — para que, SI hubiera una fuga,
        // cumpliera igual las 2 condiciones que exige el catálogo.
        $recursos['productoCampana']->update(['publicado' => true]);
        $recursos['campana']->update(['estado' => 'activa']);

        $this->autenticarComoEmpleadoDistribuidoraA();

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $idsEnCatalogo = collect($respuesta->json('data'))->pluck('id');
        $this->assertNotContains($recursos['productoCampana']->id, $idsEnCatalogo);
    }

    /**
     * Refuerzo sin ambigüedad de rol: aquí el usuario SÍ tiene el permiso
     * correcto para tocar marcas (admin_distribuidora) — así el 404 solo
     * puede venir del Global Scope de tenant, no de un rechazo de permiso
     * que hubiera dado el mismo resultado por otra razón.
     */
    public function test_un_admin_distribuidora_con_permiso_valido_tampoco_puede_ver_una_marca_de_otra_distribuidora(): void
    {
        $recursos = $this->crearRecursosDeOtraDistribuidora();
        $this->autenticarComoAdminDistribuidoraA();

        $this->getJson("/api/marcas/{$recursos['marca']->id}")->assertStatus(404);
        $this->patchJson("/api/marcas/{$recursos['marca']->id}", ['nombre' => 'Hackeado'])->assertStatus(404);
    }
}
