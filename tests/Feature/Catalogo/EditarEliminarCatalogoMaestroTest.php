<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-247 — El admin general edita y elimina líneas, marcas, temporadas y
 * productos del catálogo maestro. El borrado se bloquea si algo depende del
 * registro, queda en la auditoría y ningún otro rol puede hacerlo.
 */
class EditarEliminarCatalogoMaestroTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private const COMPONENTE = 'admin.catalogo-maestro';

    private function comoAdminGeneral(): Usuario
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $admin = $this->adminGeneral();
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return $admin;
    }

    private function comoAdminDistribuidora(): Usuario
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    private function productoNuevo(string $modelo = 'TG247-X'): Producto
    {
        return Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => $modelo,
            'nombre' => 'Producto para borrar',
            'activo' => true,
        ]);
    }

    private function productoConStock(): Producto
    {
        $varianteId = DB::table('stock_local')->value('variante_id');
        $this->assertNotNull($varianteId, 'Los seeders de demo deberían dejar stock.');

        return Variante::findOrFail($varianteId)->producto()->firstOrFail();
    }

    private function auditado(string $accion, int $id): void
    {
        $this->assertTrue(
            Auditoria::query()->where('accion', $accion)->where('entidad_id', $id)->exists(),
            "Falta la auditoría $accion del registro $id"
        );
    }

    // ------------------------------------------------------------------
    // Editar
    // ------------------------------------------------------------------

    public function test_edita_una_linea_y_queda_en_la_auditoria(): void
    {
        $this->comoAdminGeneral();
        $linea = Linea::create(['nombre' => 'Línea vieja', 'activa' => true]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'linea', $linea->id)
            ->assertSet('editar_nombre', 'Línea vieja')
            ->set('editar_nombre', 'Línea nueva')
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('editando_tipo', '');

        $this->assertSame('Línea nueva', $linea->fresh()->nombre);
        $this->auditado('catalogo.linea.actualizada', $linea->id);
    }

    public function test_no_permite_renombrar_una_linea_con_el_nombre_de_otra(): void
    {
        $this->comoAdminGeneral();
        Linea::create(['nombre' => 'Ocupada', 'activa' => true]);
        $linea = Linea::create(['nombre' => 'Libre', 'activa' => true]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'linea', $linea->id)
            ->set('editar_nombre', 'Ocupada')
            ->call('guardarEdicion')
            ->assertHasErrors(['editar_nombre' => 'unique']);

        $this->assertSame('Libre', $linea->fresh()->nombre);
    }

    public function test_edita_una_marca_y_queda_en_la_auditoria(): void
    {
        $this->comoAdminGeneral();
        $marca = Marca::create(['nombre' => 'Marca vieja', 'activa' => true]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'marca', $marca->id)
            ->set('editar_nombre', 'Marca nueva')
            ->set('editar_descripcion', 'Con descripción')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertSame('Marca nueva', $marca->fresh()->nombre);
        $this->assertSame('Con descripción', $marca->fresh()->descripcion);
        $this->auditado('catalogo.marca.actualizada', $marca->id);
    }

    public function test_edita_una_temporada_y_avanza_su_estado(): void
    {
        $this->comoAdminGeneral();
        $temporada = Campana::create([
            'linea_id' => Linea::create(['nombre' => 'Línea temporada', 'activa' => true])->id,
            'nombre' => 'PV 2027',
            'estado' => 'borrador',
        ]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'temporada', $temporada->id)
            ->set('editar_nombre', 'Primavera-Verano 2027')
            ->set('editar_estado', 'en_importacion')
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('errorMsg', '');

        $temporada->refresh();
        $this->assertSame('Primavera-Verano 2027', $temporada->nombre);
        $this->assertSame('en_importacion', $temporada->estado);
        $this->auditado('catalogo.temporada.actualizada', $temporada->id);
    }

    public function test_una_temporada_no_salta_estados_y_se_explica(): void
    {
        $this->comoAdminGeneral();
        $temporada = Campana::create([
            'linea_id' => Linea::create(['nombre' => 'Línea saltos', 'activa' => true])->id,
            'nombre' => 'OI 2027',
            'estado' => 'borrador',
        ]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'temporada', $temporada->id)
            ->set('editar_estado', 'archivada')
            ->call('guardarEdicion')
            ->assertSet('errorMsg', fn ($mensaje) => str_contains($mensaje, 'en_importacion'));

        $this->assertSame('borrador', $temporada->fresh()->estado);
    }

    public function test_edita_un_producto_incluida_su_marca(): void
    {
        $this->comoAdminGeneral();
        $producto = $this->productoNuevo();
        $otraMarca = Marca::create(['nombre' => 'Otra marca TG247', 'activa' => true]);

        Livewire::test(self::COMPONENTE)
            ->call('editar', 'producto', $producto->id)
            ->set('editar_nombre', 'Producto renombrado')
            ->set('editar_modelo', 'TG247-Y')
            ->set('editar_marca_id', (string) $otraMarca->id)
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('errorMsg', '');

        $producto->refresh();
        $this->assertSame('Producto renombrado', $producto->nombre);
        $this->assertSame('TG247-Y', $producto->modelo);
        $this->assertSame($otraMarca->id, (int) $producto->marca_id);
        $this->auditado('catalogo.producto.actualizado', $producto->id);
    }

    public function test_desactivar_es_la_alternativa_y_queda_auditado(): void
    {
        $this->comoAdminGeneral();
        $marca = Marca::create(['nombre' => 'Marca a desactivar', 'activa' => true]);
        $producto = $this->productoNuevo('TG247-D');

        Livewire::test(self::COMPONENTE)
            ->call('alternarActivo', 'marca', $marca->id)
            ->call('alternarActivo', 'producto', $producto->id);

        $this->assertFalse($marca->fresh()->activa);
        $this->assertFalse($producto->fresh()->activo);
        $this->auditado('catalogo.marca.actualizada', $marca->id);
        $this->auditado('catalogo.producto.actualizado', $producto->id);
    }

    // ------------------------------------------------------------------
    // Eliminar sin dependencias
    // ------------------------------------------------------------------

    public function test_elimina_una_linea_sin_dependencias(): void
    {
        $this->comoAdminGeneral();
        $linea = Linea::create(['nombre' => 'Línea vacía', 'activa' => true]);
        $linea->marcas()->attach(Marca::firstOrFail()->id);

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'linea', $linea->id)
            ->assertSet('errorMsg', '')
            ->assertDispatched('guardado');

        $this->assertDatabaseMissing('lineas', ['id' => $linea->id]);
        $this->assertDatabaseMissing('linea_marca', ['linea_id' => $linea->id]);
        $this->auditado('catalogo.linea.eliminada', $linea->id);
    }

    public function test_elimina_una_marca_sin_productos(): void
    {
        $this->comoAdminGeneral();
        $marca = Marca::create(['nombre' => 'Marca vacía', 'activa' => true]);

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'marca', $marca->id)
            ->assertSet('errorMsg', '');

        $this->assertDatabaseMissing('marcas', ['id' => $marca->id]);
        $this->auditado('catalogo.marca.eliminada', $marca->id);
    }

    public function test_elimina_una_temporada_sin_productos(): void
    {
        $this->comoAdminGeneral();
        $temporada = Campana::create([
            'linea_id' => Linea::create(['nombre' => 'Línea con temporada vacía', 'activa' => true])->id,
            'nombre' => 'Temporada vacía',
            'estado' => 'borrador',
        ]);

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'temporada', $temporada->id)
            ->assertSet('errorMsg', '');

        $this->assertDatabaseMissing('campanas', ['id' => $temporada->id]);
        $this->auditado('catalogo.temporada.eliminada', $temporada->id);
    }

    public function test_elimina_un_producto_sin_operacion_junto_con_sus_tallas_y_publicaciones(): void
    {
        $this->comoAdminGeneral();
        $producto = $this->productoNuevo('TG247-B');
        $variante = Variante::create([
            'producto_id' => $producto->id,
            'talla_id' => Talla::firstOrFail()->id,
            'color_id' => Color::firstOrFail()->id,
            'sku' => 'TG247-B-SKU',
            'activa' => true,
        ]);
        $publicacion = ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => Campana::firstOrFail()->id,
            'codigo_catalogo' => 'TG247-B-COD',
            'precio_publico' => 500,
            'activo' => true,
        ]);

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'producto', $producto->id)
            ->assertSet('errorMsg', '');

        $this->assertDatabaseMissing('productos', ['id' => $producto->id]);
        $this->assertDatabaseMissing('variantes', ['id' => $variante->id]);
        $this->assertDatabaseMissing('producto_campana', ['id' => $publicacion->id]);
        $this->auditado('catalogo.producto.eliminado', $producto->id);
    }

    // ------------------------------------------------------------------
    // Bloqueo por dependencias
    // ------------------------------------------------------------------

    public function test_no_elimina_una_marca_con_productos_y_lo_explica(): void
    {
        $this->comoAdminGeneral();
        $marca = Marca::create(['nombre' => 'Marca con productos', 'activa' => true]);
        foreach (['A', 'B'] as $letra) {
            Producto::create([
                'marca_id' => $marca->id,
                'categoria_id' => CategoriaProducto::firstOrFail()->id,
                'modelo' => 'TG247-M' . $letra,
                'nombre' => 'Producto ' . $letra,
                'activo' => true,
            ]);
        }

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'marca', $marca->id)
            ->assertSet('errorMsg', 'No se puede eliminar la marca: tiene 2 productos. Desactívala o mueve sus productos a otra marca.')
            ->assertNotDispatched('guardado');

        $this->assertDatabaseHas('marcas', ['id' => $marca->id]);
        $this->assertFalse(Auditoria::where('accion', 'catalogo.marca.eliminada')->exists());
    }

    public function test_no_elimina_una_linea_con_temporadas_ni_que_vendan_distribuidoras(): void
    {
        $this->comoAdminGeneral();
        $linea = Linea::query()
            ->whereIn('id', DB::table('distribuidora_linea')->select('linea_id'))
            ->firstOrFail();

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'linea', $linea->id)
            ->assertSet('errorMsg', fn ($mensaje) => str_starts_with($mensaje, 'No se puede eliminar la línea: tiene ')
                && str_contains($mensaje, 'distribuidora')
                && str_contains($mensaje, 'Desactívala'));

        $this->assertDatabaseHas('lineas', ['id' => $linea->id]);
    }

    public function test_no_elimina_una_temporada_con_productos_publicados(): void
    {
        $this->comoAdminGeneral();
        $temporada = Campana::query()->has('productosCampana')->firstOrFail();
        $publicaciones = $temporada->productosCampana()->count();

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'temporada', $temporada->id)
            ->assertSet('errorMsg', fn ($mensaje) => str_starts_with($mensaje, "No se puede eliminar la temporada: tiene {$publicaciones} producto"));

        $this->assertDatabaseHas('campanas', ['id' => $temporada->id]);
        $this->assertSame($publicaciones, $temporada->productosCampana()->count());
    }

    public function test_no_elimina_un_producto_con_stock_de_distribuidoras_ni_toca_el_stock(): void
    {
        $this->comoAdminGeneral();
        $producto = $this->productoConStock();
        $stockAntes = DB::table('stock_local')->count();

        Livewire::test(self::COMPONENTE)
            ->call('eliminar', 'producto', $producto->id)
            ->assertSet('errorMsg', fn ($mensaje) => str_starts_with($mensaje, 'No se puede eliminar el producto: tiene ')
                && str_contains($mensaje, 'stock')
                && str_contains($mensaje, 'Desactívalo'));

        $this->assertDatabaseHas('productos', ['id' => $producto->id]);
        $this->assertSame($stockAntes, DB::table('stock_local')->count());
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    public function test_api_elimina_y_responde_409_cuando_hay_dependencias(): void
    {
        Sanctum::actingAs($this->adminGeneral());
        Tenant::olvidarCache();

        $vacia = Marca::create(['nombre' => 'Marca API vacía', 'activa' => true]);
        $this->deleteJson("/api/marcas/{$vacia->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Marca eliminada.');
        $this->assertDatabaseMissing('marcas', ['id' => $vacia->id]);

        $producto = $this->productoConStock();
        $this->deleteJson("/api/productos/{$producto->id}")
            ->assertStatus(409)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_starts_with($m, 'No se puede eliminar el producto'))->etc());
    }

    // ------------------------------------------------------------------
    // Solo el admin general
    // ------------------------------------------------------------------

    public function test_otro_rol_no_entra_a_la_pantalla_ni_al_componente(): void
    {
        $this->comoAdminDistribuidora();

        $this->get('/admin/catalogo')->assertForbidden();
        Livewire::test(self::COMPONENTE)->assertForbidden();
    }

    public function test_otro_rol_no_puede_eliminar_por_api(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
        Tenant::olvidarCache();
        $marca = Marca::create(['nombre' => 'Marca protegida', 'activa' => true]);
        $linea = Linea::create(['nombre' => 'Línea protegida', 'activa' => true]);

        $this->deleteJson("/api/marcas/{$marca->id}")->assertForbidden();
        $this->deleteJson("/api/lineas/{$linea->id}")->assertForbidden();
        $this->deleteJson('/api/productos/' . $this->productoNuevo('TG247-P')->id)->assertForbidden();
        $this->deleteJson('/api/campanas/' . Campana::firstOrFail()->id)->assertForbidden();

        $this->assertDatabaseHas('marcas', ['id' => $marca->id]);
        $this->assertDatabaseHas('lineas', ['id' => $linea->id]);
    }

    public function test_la_auditoria_muestra_los_borrados_en_espanol(): void
    {
        $this->assertSame('Marca eliminada', \App\Support\Auditoria\EtiquetasAuditoria::ETIQUETAS['catalogo.marca.eliminada']);
        $this->assertSame('Línea eliminada', \App\Support\Auditoria\EtiquetasAuditoria::ETIQUETAS['catalogo.linea.eliminada']);
        $this->assertSame('Temporada eliminada', \App\Support\Auditoria\EtiquetasAuditoria::ETIQUETAS['catalogo.temporada.eliminada']);
        $this->assertSame('Producto eliminado', \App\Support\Auditoria\EtiquetasAuditoria::ETIQUETAS['catalogo.producto.eliminado']);
    }
}
