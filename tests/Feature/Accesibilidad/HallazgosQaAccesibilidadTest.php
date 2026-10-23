<?php

namespace Tests\Feature\Accesibilidad;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgos de QA (Aure, Playwright + axe-core): barra lateral plegada fuera
 * del orden de tabulación, nombres accesibles que empiezan con el texto
 * visible, paginación en español sin aria-label en un span sin rol, imágenes
 * de stock sin src vacío y rutas del panel que responden 403 (no /login).
 */
class HallazgosQaAccesibilidadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function usuario(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    /** QA-A11Y-01 y QA-A11Y-03 en el panel del admin general. */
    public function test_la_barra_lateral_plegada_del_admin_es_inert_y_el_boton_nombra_su_texto(): void
    {
        $html = $this->actingAs($this->usuario(AdminGeneralSeeder::EMAIL))
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-hidden="true" inert :aria-hidden="(!sidebarOpen).toString()" :inert="!sidebarOpen" data-admin-sidebar', $html);
        $this->assertStringContainsString('aria-label="Footwear Point Admin, abrir o cerrar menú"', $html);
        $this->assertStringNotContainsString('aria-label="Abrir o cerrar menú"', $html);
    }

    /** QA-A11Y-01 y QA-A11Y-03 en el panel de la distribuidora (incluye el buscador). */
    public function test_la_barra_lateral_plegada_del_panel_es_inert_y_los_nombres_coinciden(): void
    {
        $html = $this->actingAs($this->usuario(DemoDistribuidoraSeeder::ADMIN))
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-hidden="true" inert :aria-hidden="(!sidebarOpen).toString()" :inert="!sidebarOpen"', $html);
        $this->assertStringContainsString('aria-label="Footwear Point Panel, abrir o cerrar menú"', $html);
        $this->assertStringNotContainsString('aria-label="Buscar en el panel (Ctrl+K)"', $html);
        $this->assertStringContainsString('Buscar…<span class="sr-only"> en el panel</span>', $html);
    }

    /** QA-A11Y-02: los encabezados de tabla ya no usan el gris de 4.4:1 sobre fp-page. */
    public function test_los_encabezados_de_tabla_usan_un_gris_con_contraste_suficiente(): void
    {
        $html = $this->actingAs($this->usuario(AdminGeneralSeeder::EMAIL))
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<thead[^>]*text-fp-text-muted/', $html);
        $this->assertStringNotContainsString('color: var(--color-fp-text-muted); }', file_get_contents(resource_path('css/app.css')) === false ? '' : $this->reglaThead());
    }

    private function reglaThead(): string
    {
        preg_match('/\.fp-tabla thead \{[^}]*\}/', file_get_contents(resource_path('css/app.css')), $m);

        return $m[0] ?? '';
    }

    public static function paginas(): array
    {
        return ['primera página' => [1], 'última página' => [5]];
    }

    /** QA-A11Y-04: paginación de Livewire en español, sin aria-label en span sin rol. */
    #[\PHPUnit\Framework\Attributes\DataProvider('paginas')]
    public function test_la_paginacion_esta_en_espanol_y_sin_atributos_prohibidos(int $pagina): void
    {
        $paginador = new LengthAwarePaginator(range(1, 10), 50, 10, $pagina);

        foreach (['livewire::tailwind', 'livewire::simple-tailwind'] as $vista) {
            $html = (string) $paginador->links($vista);

            $this->assertStringNotContainsString('Previous', $html, $vista);
            $this->assertStringNotContainsString('Next', $html, $vista);
            $this->assertStringContainsString('Anterior', $html, $vista);
            $this->assertStringContainsString('Siguiente', $html, $vista);
            $this->assertDoesNotMatchRegularExpression('/<span(?![^>]*\brole=)[^>]*\baria-label=/', $html, $vista);
        }

        $html = (string) $paginador->links('livewire::tailwind');
        $this->assertStringContainsString('aria-label="Paginación"', $html);
        $deshabilitado = $pagina === 1 ? 'Anterior' : 'Siguiente';
        $this->assertStringContainsString('role="link" aria-disabled="true" aria-label="'.$deshabilitado.'"', $html);
    }

    /** QA-IMG-01: en /stock ninguna imagen de Alpine se pinta antes de tener src. */
    public function test_stock_no_pinta_imagenes_con_src_vacio(): void
    {
        $this->actingAs($this->usuario(DemoDistribuidoraSeeder::EMPLEADO));

        $html = Livewire::test('stock.index')->html();

        $this->assertStringContainsString('<template x-if="imagenElegida"><img :src="imagenElegida"', $html);
        $this->assertStringContainsString('<template x-if="r.imagen"><img :src="r.imagen"', $html);
        $this->assertDoesNotMatchRegularExpression('/<img(?![^>]*:src)[^>]*\ssrc=""/', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<!<template x-if="imagenElegida">)<img :src="imagenElegida"/', $html);
    }

    public static function rutasDeOtroRol(): array
    {
        return [
            'admin de distribuidora en /admin' => [DemoDistribuidoraSeeder::ADMIN, '/admin'],
            'admin de distribuidora en /admin/planes' => [DemoDistribuidoraSeeder::ADMIN, '/admin/planes'],
            'empleado en /distribuidora/clientes-directos' => [DemoDistribuidoraSeeder::EMPLEADO, '/distribuidora/clientes-directos'],
        ];
    }

    /** QA-SES-01: un rol equivocado recibe 403 y conserva su sesión; nunca se le manda a /login. */
    #[\PHPUnit\Framework\Attributes\DataProvider('rutasDeOtroRol')]
    public function test_un_rol_sin_acceso_recibe_403_y_no_pierde_la_sesion(string $email, string $ruta): void
    {
        $usuario = $this->usuario($email);

        $this->actingAs($usuario)->get($ruta)->assertForbidden();
        $this->assertAuthenticatedAs($usuario);
    }

    /** QA-SES-01: las rutas reportadas abren con su rol correcto. */
    public function test_las_rutas_reportadas_abren_con_el_rol_correcto(): void
    {
        $this->actingAs($this->usuario(AdminGeneralSeeder::EMAIL))->get('/admin')->assertOk();
        $this->actingAs($this->usuario(DemoDistribuidoraSeeder::ADMIN))->get('/distribuidora/clientes-directos')->assertOk();
    }

    /** QA-SES-01: entrar desde otro dispositivo no invalida la cookie remember del primero. */
    public function test_entrar_en_otro_dispositivo_no_cambia_el_remember_token(): void
    {
        foreach ([AdminGeneralSeeder::EMAIL, DemoDistribuidoraSeeder::ADMIN] as $email) {
            $usuario = $this->usuario($email);
            $usuario->forceFill(['password' => Hash::make('Sesion-Larga-2026!'), 'estado' => 'activo', 'remember_token' => null])->save();

            foreach ([1, 2] as $dispositivo) {
                auth()->guard('web')->logoutCurrentDevice();
                Livewire::test('auth.login')
                    ->set('email', $email)
                    ->set('password', 'Sesion-Larga-2026!')
                    ->call('login')
                    ->assertHasNoErrors();
                $tokens[$dispositivo] = $usuario->fresh()->remember_token;
            }

            $this->assertNotEmpty($tokens[1]);
            $this->assertSame($tokens[1], $tokens[2], $email);
        }
    }
}