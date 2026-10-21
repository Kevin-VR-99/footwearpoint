<?php

namespace Tests\Feature\Catalogo;

use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Usuario;
use App\Services\Imagenes\RedimensionarImagenWebp;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Auditoría 21-oct-2026: cada foto de producto se guarda también en WebP de ~400 y ~1200 px. */
class VariantesImagenProductoTest extends TestCase
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
        Sanctum::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail());
    }

    public function test_al_subir_una_foto_se_guardan_la_original_y_dos_webp_redimensionados(): void
    {
        $productoCampana = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $respuesta = $this->postJson("/api/producto-campana/{$productoCampana->id}/imagenes", [
            'imagen' => UploadedFile::fake()->image('tenis.jpg', 2400, 1800),
        ])->assertCreated();

        $imagen = ProductoImagen::withoutGlobalScopes()->findOrFail($respuesta->json('data.id'));
        $this->assertNotNull($imagen->url_miniatura);
        $this->assertNotNull($imagen->url_mediana);
        $respuesta->assertJsonPath('data.url', $imagen->url)
            ->assertJsonPath('data.url_miniatura', $imagen->url_miniatura)
            ->assertJsonPath('data.url_mediana', $imagen->url_mediana);

        $archivos = collect(Storage::disk('s3')->allFiles('productos/campana/imagenes'));
        $this->assertCount(3, $archivos);

        foreach ([400 => 300, 1200 => 900] as $ancho => $alto) {
            $ruta = $archivos->first(fn ($a) => str_ends_with($a, "-{$ancho}.webp"));
            $this->assertNotNull($ruta, "Falta la versión de {$ancho} px");
            $info = getimagesizefromstring(Storage::disk('s3')->get($ruta));
            $this->assertSame([$ancho, $alto, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        }

        $this->assertSame($imagen->url_miniatura.' 400w, '.$imagen->url_mediana.' 1200w', $imagen->srcset());
    }

    public function test_una_foto_chica_no_se_agranda(): void
    {
        $archivo = UploadedFile::fake()->image('chica.png', 300, 200);

        $versiones = app(RedimensionarImagenWebp::class)->generar($archivo->getRealPath());

        $this->assertSame([300, 200], [$versiones[400]['ancho'], $versiones[400]['alto']]);
        $this->assertSame([300, 200], [$versiones[1200]['ancho'], $versiones[1200]['alto']]);
    }

    public function test_las_fotos_anteriores_sin_variantes_siguen_usando_la_original(): void
    {
        $imagen = new ProductoImagen(['url' => 'https://ejemplo.test/vieja.jpg']);

        $this->assertSame('https://ejemplo.test/vieja.jpg', $imagen->urlMiniatura());
        $this->assertSame('https://ejemplo.test/vieja.jpg', $imagen->urlMediana());
        $this->assertNull($imagen->srcset());
    }
}
