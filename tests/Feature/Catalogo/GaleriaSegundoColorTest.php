<?php

namespace Tests\Feature\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\ProponerRecortesDeFotosAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * catalogo:publicar-fotos-ia --galeria
 *
 * El segundo color de un modelo DÚO comparte publicación con el primero, y
 * esa publicación ya tiene foto: el modo normal lo salta. Con --galeria su
 * recorte entra como una foto más de la galería, sin tocar la principal.
 */
class GaleriaSegundoColorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ProductoCampana $pc;

    private ProductoImagen $principal;

    private ProductoImportadoStaging $primerColor;

    private ProductoImportadoStaging $segundoColor;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Storage::fake('s3');

        $this->pc = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->pc->imagenes()->delete();

        $this->principal = ProductoImagen::create([
            'producto_campana_id' => $this->pc->id,
            'url'                 => 'https://bucket.test/productos/campana/imagenes/primer-color.jpg',
            'orden'               => 1,
            'es_principal'        => true,
        ]);

        $importacion = ImportacionCatalogo::create([
            'linea_id'                => 1,
            'archivo_url'             => 'catalogos-ia/galeria.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'aprobado',
            'paginas'                 => 1,
            'iniciada_por_usuario_id' => Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);

        $this->primerColor = $this->fila($importacion, 'NEGRO');
        $this->segundoColor = $this->fila($importacion, 'BEIGE');
    }

    private function fila(ImportacionCatalogo $importacion, string $color): ProductoImportadoStaging
    {
        $ruta = ProponerRecortesDeFotosAction::CARPETA.'/'.strtolower($color).'.png';
        Storage::disk('s3')->put($ruta, UploadedFile::fake()->image($color.'.png', 50, 50)->getContent());

        return ProductoImportadoStaging::create([
            'importacion_id'             => $importacion->id,
            'datos_extraidos'            => ['pagina' => 1, 'codigo_catalogo' => 'DUO-613', 'color' => $color],
            'campos_dudosos'             => [],
            'estado'                     => 'aprobado',
            'producto_creado_id'         => $this->pc->producto_id,
            'producto_campana_creado_id' => $this->pc->id,
            'foto_propuesta_url'         => Storage::disk('s3')->url($ruta),
            'foto_estado'                => 'propuesta',
        ]);
    }

    public function test_agrega_el_segundo_color_con_el_orden_siguiente(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')
            ->expectsOutputToContain('Fotos agregadas a la galería: 1 de 1.')
            ->assertSuccessful();

        $imagenes = $this->pc->imagenes()->orderBy('orden')->get();
        $this->assertCount(2, $imagenes);

        $nueva = $imagenes->last();
        $this->assertSame(2, (int) $nueva->orden);
        $this->assertFalse((bool) $nueva->es_principal);
        $this->assertSame($nueva->id, (int) $this->segundoColor->refresh()->producto_imagen_galeria_id);
        $this->assertNull($this->primerColor->refresh()->producto_imagen_galeria_id);
    }

    public function test_la_principal_no_cambia(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')->assertSuccessful();

        $principal = $this->principal->refresh();
        $this->assertTrue((bool) $principal->es_principal);
        $this->assertSame(1, (int) $principal->orden);
        $this->assertSame(1, $this->pc->imagenes()->where('es_principal', true)->count());
    }

    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')->assertSuccessful();

        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')
            ->expectsOutputToContain('Fotos agregadas a la galería: 0 de 0.')
            ->assertSuccessful();

        $this->assertSame(2, $this->pc->imagenes()->count());
    }

    /** Si el admin borra la foto agregada, no vuelve a aparecer sola. */
    public function test_no_resucita_una_foto_que_el_admin_borro(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')->assertSuccessful();
        ProductoImagen::find($this->segundoColor->refresh()->producto_imagen_galeria_id)->delete();

        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')
            ->expectsOutputToContain('0 de 0.')
            ->assertSuccessful();

        $this->assertSame(1, $this->pc->imagenes()->count());
    }

    public function test_en_seco_cuenta_y_no_escribe(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --galeria')
            ->expectsOutputToContain('se agregarían a la galería: 1.')
            ->assertSuccessful();

        $this->assertSame(1, $this->pc->imagenes()->count());
        $this->assertNull($this->segundoColor->refresh()->producto_imagen_galeria_id);
        $this->assertSame([], Storage::disk('s3')->files('productos/campana/imagenes'));
    }

    /** El modo normal sigue igual: la publicación ya tiene foto, no la toca. */
    public function test_sin_galeria_sigue_saltando_la_publicacion_con_foto(): void
    {
        $this->artisan('catalogo:publicar-fotos-ia --confirmar')
            ->expectsOutputToContain('Fotos publicadas: 0 de 0.')
            ->assertSuccessful();

        $this->assertSame(1, $this->pc->imagenes()->count());
    }

    public function test_salta_el_recorte_descartado(): void
    {
        $this->segundoColor->update(['foto_estado' => 'descartada']);

        $this->artisan('catalogo:publicar-fotos-ia --galeria --confirmar')
            ->expectsOutputToContain('0 de 0.')
            ->assertSuccessful();

        $this->assertSame(1, $this->pc->imagenes()->count());
    }
}