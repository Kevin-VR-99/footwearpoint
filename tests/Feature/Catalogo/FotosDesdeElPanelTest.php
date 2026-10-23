<?php

namespace Tests\Feature\Catalogo;

use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Usuario;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * E5-05 — Poner la foto de un producto desde el panel.
 *
 * El hueco que cerró esto: las pantallas y la app ya enseñaban la foto, pero
 * no había NINGUNA forma de ponerla. Solo existía la ruta del API, así que en
 * la práctica las únicas fotos eran las que trae la semilla, y por eso en el
 * emulador los productos salían sin imagen.
 *
 * De paso, quitar una foto dejaba los archivos en el bucket: solo borraba la
 * fila. Y desde las variantes (21-oct-2026) no era un archivo, eran tres.
 */
class FotosDesdeElPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function publicacion(): ProductoCampana
    {
        return ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function panel()
    {
        return Livewire::actingAs($this->adminGeneral())->test('admin.catalogo-maestro');
    }

    private function archivosEnElBucket(): array
    {
        return Storage::disk('s3')->allFiles('productos/campana/imagenes');
    }

    // ------------------------------------------------------------------
    // Subir
    // ------------------------------------------------------------------

    public function test_el_admin_general_puede_subir_la_foto_de_una_publicacion(): void
    {
        $publicacion = $this->publicacion();

        $this->panel()
            ->call('abrirFotos', $publicacion->id)
            ->set('fotoNueva', UploadedFile::fake()->image('tenis.jpg', 1600, 1200))
            ->call('subirFoto')
            ->assertHasNoErrors();

        $foto = ProductoImagen::where('producto_campana_id', $publicacion->id)->firstOrFail();

        // La original y las dos WebP.
        $this->assertCount(3, $this->archivosEnElBucket());
        $this->assertNotNull($foto->url_miniatura);
        $this->assertNotNull($foto->url_mediana);

        // La primera siempre queda principal: una publicación con fotos y
        // ninguna principal no tendría sentido.
        $this->assertTrue($foto->es_principal);
    }

    public function test_la_pantalla_ofrece_poner_fotos_en_cada_publicacion(): void
    {
        // Lo que faltaba era justo el botón: sin él no había forma de llegar.
        $this->panel()
            ->set('pestana', 'productos')
            ->assertSeeHtml('wire:click="abrirFotos(');
    }

    public function test_un_archivo_que_no_es_jpg_ni_png_se_rechaza(): void
    {
        $publicacion = $this->publicacion();

        $this->panel()
            ->call('abrirFotos', $publicacion->id)
            ->set('fotoNueva', UploadedFile::fake()->create('catalogo.pdf', 200, 'application/pdf'))
            ->call('subirFoto')
            ->assertHasErrors('fotoNueva');

        $this->assertSame(0, ProductoImagen::where('producto_campana_id', $publicacion->id)->count());
        $this->assertSame([], $this->archivosEnElBucket());
    }

    public function test_una_foto_de_mas_de_cinco_megas_se_rechaza(): void
    {
        $publicacion = $this->publicacion();

        $this->panel()
            ->call('abrirFotos', $publicacion->id)
            ->set('fotoNueva', UploadedFile::fake()->image('enorme.jpg')->size(5121))
            ->call('subirFoto')
            ->assertHasErrors('fotoNueva');

        $this->assertSame(0, ProductoImagen::where('producto_campana_id', $publicacion->id)->count());
    }

    // ------------------------------------------------------------------
    // Principal
    // ------------------------------------------------------------------

    public function test_al_hacer_principal_otra_la_anterior_deja_de_serlo(): void
    {
        $publicacion = $this->publicacion();
        $accion = app(GestionarImagenProductoCampanaAction::class);

        $primera = $accion->agregar($publicacion, UploadedFile::fake()->image('una.jpg', 800, 600));
        $segunda = $accion->agregar($publicacion, UploadedFile::fake()->image('otra.jpg', 800, 600));

        $this->panel()
            ->call('abrirFotos', $publicacion->id)
            ->call('hacerPrincipal', $segunda->id);

        $this->assertFalse($primera->refresh()->es_principal);
        $this->assertTrue($segunda->refresh()->es_principal);
    }

    /**
     * El id de la foto viaja desde el navegador. Sin la comprobación se
     * podría tocar la foto de cualquier otra publicación.
     */
    public function test_no_se_puede_tocar_la_foto_de_otra_publicacion(): void
    {
        $publicaciones = ProductoCampana::withoutGlobalScopes()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $publicaciones, 'La semilla necesita dos publicaciones.');

        $ajena = app(GestionarImagenProductoCampanaAction::class)
            ->agregar($publicaciones[1], UploadedFile::fake()->image('ajena.jpg', 800, 600));

        $panel = $this->panel()->call('abrirFotos', $publicaciones[0]->id);

        // Tiene que reventar, no contestar con un aviso suave: un aviso haría
        // pasar el intento por bueno.
        $this->assertThrows(
            fn () => $panel->call('quitarFoto', $ajena->id),
            ModelNotFoundException::class,
        );

        $this->assertDatabaseHas('producto_imagenes', ['id' => $ajena->id]);

        $this->assertThrows(
            fn () => $panel->call('hacerPrincipal', $ajena->id),
            ModelNotFoundException::class,
        );
    }

    // ------------------------------------------------------------------
    // Quitar: la parte que dejaba basura
    // ------------------------------------------------------------------

    public function test_quitar_una_foto_borra_los_tres_archivos_del_bucket(): void
    {
        $publicacion = $this->publicacion();

        $foto = app(GestionarImagenProductoCampanaAction::class)
            ->agregar($publicacion, UploadedFile::fake()->image('tenis.jpg', 1600, 1200));

        $this->assertCount(3, $this->archivosEnElBucket());

        $this->panel()
            ->call('abrirFotos', $publicacion->id)
            ->call('quitarFoto', $foto->id);

        // Antes esto quedaba en 3: solo se borraba la fila.
        $this->assertSame([], $this->archivosEnElBucket());
        $this->assertDatabaseMissing('producto_imagenes', ['id' => $foto->id]);
    }

    /**
     * Una foto vieja, de antes de las variantes, solo tiene la original: no
     * hay que intentar borrar rutas que no existen.
     */
    public function test_una_foto_sin_variantes_se_borra_igual(): void
    {
        $publicacion = $this->publicacion();

        $ruta = 'productos/campana/imagenes/vieja.jpg';
        Storage::disk('s3')->put($ruta, 'contenido');

        $foto = ProductoImagen::create([
            'producto_campana_id' => $publicacion->id,
            'url'                 => Storage::disk('s3')->url($ruta),
            'url_miniatura'       => null,
            'url_mediana'         => null,
            'orden'               => 1,
            'es_principal'        => true,
        ]);

        app(GestionarImagenProductoCampanaAction::class)->eliminar($foto);

        $this->assertFalse(Storage::disk('s3')->exists($ruta));
        $this->assertDatabaseMissing('producto_imagenes', ['id' => $foto->id]);
    }

    /**
     * Una URL que no apunta a nuestra carpeta (otro bucket, o de antes de un
     * cambio de configuración) no debe dejar al admin sin poder quitar la
     * foto nunca. Se acepta el huérfano, anotado en el log.
     */
    public function test_una_url_que_no_se_puede_mapear_no_bloquea_el_borrado(): void
    {
        $publicacion = $this->publicacion();

        $foto = ProductoImagen::create([
            'producto_campana_id' => $publicacion->id,
            'url'                 => 'https://otro-servidor.example/fotos/antigua.jpg',
            'orden'               => 1,
            'es_principal'        => true,
        ]);

        app(GestionarImagenProductoCampanaAction::class)->eliminar($foto);

        $this->assertDatabaseMissing('producto_imagenes', ['id' => $foto->id]);
    }

    // ------------------------------------------------------------------
    // Alta desde una ruta: lo que necesitará el recorte de la IA
    // ------------------------------------------------------------------

    public function test_se_puede_dar_de_alta_una_foto_que_ya_esta_en_el_disco(): void
    {
        $publicacion = $this->publicacion();

        // Como el recorte que hará el worker: un archivo en disco, que no
        // subió nadie por un formulario.
        $local = tempnam(sys_get_temp_dir(), 'recorte').'.png';
        $lienzo = imagecreatetruecolor(600, 450);
        imagepng($lienzo, $local);
        imagedestroy($lienzo);

        $foto = app(GestionarImagenProductoCampanaAction::class)
            ->agregarDesdeRuta($publicacion, $local);

        @unlink($local);

        $this->assertCount(3, $this->archivosEnElBucket());
        $this->assertTrue($foto->es_principal);
        $this->assertNotNull($foto->url_miniatura);
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    public function test_quien_no_es_admin_general_no_entra_a_la_pantalla(): void
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Livewire::actingAs($empleado)
            ->test('admin.catalogo-maestro')
            ->assertForbidden();
    }
}
