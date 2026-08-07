<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoConsultableTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoEmpleado(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    /**
     * Se crea y se avanza directo con Eloquent (no por la API) para no
     * depender de ninguna Factory ni de la validación de transición paso a
     * paso — aquí solo interesa dejar la campaña en 'activa' para el
     * escenario de prueba.
     */
    private function crearCampanaActiva(int $marcaId): Campana
    {
        $campana = Campana::create([
            'marca_id' => $marcaId,
            'nombre'   => 'Campaña Activa Para Prueba ' . uniqid(),
        ]);
        $campana->update(['estado' => 'activa']);

        return $campana->fresh();
    }

    private function crearPublicacion(Producto $producto, Campana $campana, bool $publicado): ProductoCampana
    {
        return ProductoCampana::create([
            'producto_id'               => $producto->id,
            'campana_id'                => $campana->id,
            'codigo_catalogo'           => 'TEST-' . uniqid(),
            'precio_mayorista'          => 500,
            'precio_minorista_sugerido' => 800,
            'publicado'                 => $publicado,
        ]);
    }

    /**
     * La prueba explícita que pide el documento de tareas (sección 4,
     * Paquete B): "Un producto_campana no publicado no aparece en GET
     * /api/catalogo."
     */
    public function test_una_publicacion_no_publicada_no_aparece_en_el_catalogo(): void
    {
        $this->autenticarComoEmpleado();

        $producto = Producto::firstOrFail();
        $campana = $this->crearCampanaActiva($producto->marca_id);
        $publicacion = $this->crearPublicacion($producto, $campana, publicado: false);

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $idsEnCatalogo = collect($respuesta->json('data'))->pluck('id');
        $this->assertNotContains($publicacion->id, $idsEnCatalogo);
    }

    public function test_una_publicacion_publicada_en_campana_activa_si_aparece(): void
    {
        $this->autenticarComoEmpleado();

        $producto = Producto::firstOrFail();
        $campana = $this->crearCampanaActiva($producto->marca_id);
        $publicacion = $this->crearPublicacion($producto, $campana, publicado: true);

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $idsEnCatalogo = collect($respuesta->json('data'))->pluck('id');
        $this->assertContains($publicacion->id, $idsEnCatalogo);
    }

    public function test_una_publicacion_publicada_pero_en_campana_no_activa_no_aparece(): void
    {
        $this->autenticarComoEmpleado();

        $producto = Producto::firstOrFail();
        $campana = Campana::create([
            'marca_id' => $producto->marca_id,
            'nombre'   => 'Campaña En Borrador Para Prueba',
        ]); // nace en 'borrador', nunca se avanza

        $publicacion = $this->crearPublicacion($producto, $campana, publicado: true);

        $respuesta = $this->getJson('/api/catalogo')->assertOk();

        $idsEnCatalogo = collect($respuesta->json('data'))->pluck('id');
        $this->assertNotContains($publicacion->id, $idsEnCatalogo);
    }

    public function test_sin_autenticar_no_se_puede_consultar_el_catalogo(): void
    {
        $this->getJson('/api/catalogo')->assertStatus(401);
    }
}
