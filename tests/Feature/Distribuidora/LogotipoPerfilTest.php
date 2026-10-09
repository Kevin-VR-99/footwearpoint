<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El logotipo de la distribuidora (Configuración > Datos generales) se guarda
 * y se ve en el directorio. En producción se guardó el perfil sin el archivo:
 * el botón se podía usar mientras el logotipo se seguía subiendo.
 */
class LogotipoPerfilTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('s3');
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->ramirez()->id);
    }

    private function ramirez(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG));
    }

    public function test_el_logotipo_se_guarda_y_sale_en_el_directorio(): void
    {
        Livewire::test('distribuidora.perfil')
            ->set('logotipo', $this->png())
            ->call('guardarPerfil')
            ->assertHasNoErrors()
            ->assertDispatched('guardado')
            ->assertSet('logotipo', null);

        $url = $this->ramirez()->logotipo_url;
        $this->assertNotNull($url);
        $this->assertCount(1, Storage::disk('s3')->files('distribuidoras/logotipos'));

        Livewire::test('distribuidora.perfil')->assertSet('logotipo_url_actual', $url);
        $this->get('/marketplace')->assertOk()->assertSee('src="'.e($url).'"', false);
    }

    public function test_si_el_logotipo_no_queda_guardado_no_dice_listo(): void
    {
        $this->mock(ActualizarPerfilDistribuidoraAction::class)
            ->shouldReceive('ejecutar')
            ->andReturnUsing(fn (Distribuidora $d) => $d);

        Livewire::test('distribuidora.perfil')
            ->set('logotipo', $this->png())
            ->call('guardarPerfil')
            ->assertHasErrors('logotipo')
            ->assertNotDispatched('guardado')
            ->assertSee('No pudimos guardar el logotipo');
    }

    public function test_no_se_puede_guardar_mientras_se_sube_el_logotipo(): void
    {
        Livewire::test('distribuidora.perfil')
            ->assertSeeHtml('x-on:livewire-upload-start="subiendo = true"')
            ->assertSeeHtml('x-on:livewire-upload-finish="subiendo = false"')
            ->assertSeeHtml('wire:target="logotipo,guardarPerfil" x-bind:disabled="subiendo"')
            ->assertSee('Subiendo logotipo…');
    }
}
