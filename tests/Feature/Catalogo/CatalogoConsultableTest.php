<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Usuario;
use App\Services\Distribuidora\GestionarOfertaDistribuidoraAction;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/catalogo: qué ve una distribuidora del catálogo compartido
 * (regla 4.4 del diseño, TG-213).
 *
 * Aparece un producto cuando se cumple todo:
 *   - su temporada está activa,
 *   - esa temporada es de una línea que la distribuidora vende,
 *   - el producto está activo en la temporada,
 *   - y la distribuidora no lo ocultó.
 */
class CatalogoConsultableTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoEmpleado(): void
    {
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    /** Un producto del catálogo, en su propia línea y temporada. */
    private function publicar(string $sufijo, string $estadoTemporada = 'activa', bool $activo = true): ProductoCampana
    {
        $linea = Linea::create(['nombre' => 'Línea '.$sufijo, 'activa' => true]);

        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'MOD-'.$sufijo,
            'nombre' => 'Producto '.$sufijo,
            'activo' => true,
        ]);

        $campana = Campana::create([
            'linea_id' => $linea->id,
            'nombre' => 'Temporada '.$sufijo,
            'estado' => $estadoTemporada,
        ]);

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'TEST-'.$sufijo,
            'precio_publico' => 800,
            'activo' => $activo,
        ]);
    }

    private function venderEsaLinea(ProductoCampana $publicacion): void
    {
        DistribuidoraLinea::withoutGlobalScopes()->firstOrCreate(
            [
                'distribuidora_id' => Distribuidora::where('slug', 'calzados-ramirez')->value('id'),
                'linea_id' => $publicacion->campana->linea_id,
            ],
            ['es_extra' => false, 'activa' => true, 'fecha_activacion' => now()]
        );
    }

    private function idsDelCatalogo(): \Illuminate\Support\Collection
    {
        return collect($this->getJson('/api/catalogo')->assertOk()->json('data'))->pluck('id');
    }

    public function test_un_producto_de_una_linea_que_vende_si_aparece(): void
    {
        $publicacion = $this->publicar('visible');
        $this->venderEsaLinea($publicacion);

        $this->comoEmpleado();

        $this->assertContains($publicacion->id, $this->idsDelCatalogo());
    }

    public function test_un_producto_de_una_linea_que_no_vende_no_aparece(): void
    {
        $publicacion = $this->publicar('ajena');

        $this->comoEmpleado();

        $this->assertNotContains($publicacion->id, $this->idsDelCatalogo());
    }

    public function test_un_producto_de_una_temporada_que_no_esta_activa_no_aparece(): void
    {
        $publicacion = $this->publicar('en-borrador', estadoTemporada: 'borrador');
        $this->venderEsaLinea($publicacion);

        $this->comoEmpleado();

        $this->assertNotContains($publicacion->id, $this->idsDelCatalogo());
    }

    /** El admin general puede retirar un producto de la temporada. */
    public function test_un_producto_retirado_de_la_temporada_no_aparece(): void
    {
        $publicacion = $this->publicar('retirado', activo: false);
        $this->venderEsaLinea($publicacion);

        $this->comoEmpleado();

        $this->assertNotContains($publicacion->id, $this->idsDelCatalogo());
    }

    public function test_un_producto_que_la_distribuidora_oculto_no_aparece(): void
    {
        $publicacion = $this->publicar('oculto');
        $this->venderEsaLinea($publicacion);

        Sanctum::actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        app(GestionarOfertaDistribuidoraAction::class)->ocultar($publicacion->id);

        $this->comoEmpleado();

        $this->assertNotContains($publicacion->id, $this->idsDelCatalogo());
    }

    public function test_sin_autenticar_no_se_puede_consultar_el_catalogo(): void
    {
        $this->getJson('/api/catalogo')->assertStatus(401);
    }
}
