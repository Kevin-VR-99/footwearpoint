<?php

namespace Tests\Feature\AppMovil;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DescargarApkAdminTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id() ?? 0);

        return $usuario;
    }

    public function test_el_admin_descarga_el_apk_desde_el_menu(): void
    {
        Storage::fake('productos');
        Storage::disk('productos')->put('app-movil/footwearpoint.apk', 'APK-FALSO');

        $this->como(AdminGeneralSeeder::EMAIL);

        $menu = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $menu = substr($menu, strpos($menu, 'data-menu-usuario'));
        $this->assertStringContainsString('data-menu-apk', $menu);
        $this->assertStringContainsString('Descargar app', $menu);
        $this->assertLessThan(strpos($menu, 'data-menu-apk'), strpos($menu, 'data-menu-ingresos'));
        $this->assertLessThan(strpos($menu, 'Cerrar sesión'), strpos($menu, 'data-menu-apk'));

        $respuesta = $this->get(route('admin.app-movil'));
        $respuesta->assertOk()->assertDownload('footwearpoint.apk');
        $this->assertSame('APK-FALSO', $respuesta->streamedContent());
        $this->assertStringContainsString(
            'application/vnd.android.package-archive',
            (string) $respuesta->headers->get('content-type')
        );
    }

    public function test_sin_archivo_avisa_y_otra_persona_no_lo_descarga(): void
    {
        Storage::fake('productos');

        $this->como(AdminGeneralSeeder::EMAIL);
        $this->get(route('admin.app-movil'))
            ->assertNotFound()
            ->assertSee('Todavía no hay una app publicada.');

        $this->como('admin@calzadosramirez.test');
        $this->get(route('admin.app-movil'))->assertForbidden();
        $panel = $this->get(route('stock.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-menu-apk', $panel);
        $this->assertStringNotContainsString(route('admin.app-movil'), $panel);
    }
}
