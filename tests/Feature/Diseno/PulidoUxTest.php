<?php

namespace Tests\Feature\Diseno;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pulido UX: reportes reorganizados, confirmaciones, etiquetas, cargas y avisos. */
class PulidoUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    public function test_reportes_tiene_filtros_plegables_kpis_y_pestanas(): void
    {
        $this->como('admin@calzadosramirez.test');

        $this->get(route('reportes.index'))
            ->assertOk()
            ->assertSee('data-filtros-reporte', false)
            ->assertSee('data-kpis-reporte', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('Por cobrar')
            ->assertSee('Demanda')
            ->assertSee('Descargar PDF')
            ->assertDontSee('Encabezado compacto', false);
    }

    public function test_limpiar_filtros_regresa_todo_y_los_enlaces_de_descarga_los_siguen(): void
    {
        $this->como('admin@calzadosramirez.test');

        Livewire::test('reportes.index')
            ->set('desde', '2030-01-01')
            ->set('estado', 'entregado')
            ->assertSee('estado=entregado', false)
            ->assertSee('data-limpiar-filtros', false)
            ->call('limpiarFiltros')
            ->assertSet('desde', '')
            ->assertSet('estado', '')
            ->assertDontSee('estado=entregado', false);
    }

    public function test_buscar_cambio_sin_folio_pide_el_folio(): void
    {
        $this->como('admin@calzadosramirez.test');

        Livewire::test('cambios.index')
            ->set('folioVenta', '  ')
            ->call('buscarVenta')
            ->assertSet('errorMsg', 'Escribe el folio de la venta que quieres cambiar.');
    }

    public function test_acciones_destructivas_piden_confirmacion(): void
    {
        $vistas = [
            'admin/⚡planes-index.blade.php' => 'wire:click="desactivar({{ $plan->id }})" wire:confirm=',
            'catalogo/⚡mis-lineas.blade.php' => 'wire:click="desactivar({{ $linea->id }})" wire:confirm=',
            'catalogo/⚡precios.blade.php' => 'wire:click="quitarPrecio({{ $pc->id }})" wire:confirm=',
        ];

        foreach ($vistas as $vista => $fragmento) {
            $this->assertStringContainsString($fragmento, file_get_contents(resource_path('views/components/'.$vista)), $vista);
        }
    }

    public function test_login_tiene_etiquetas_ligadas_un_h1_y_sin_desborde(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('<label for="login-email"', $html);
        $this->assertStringContainsString('id="login-email"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('fp-acceso-capa', $html);
    }

    public function test_el_admin_general_tiene_avisos_flotantes(): void
    {
        $this->como('mvpla.footwearpoint@gmail.com');

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('data-toast', false);
    }

    public function test_la_hoja_de_estilos_trae_carga_esqueleto_y_respeta_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('[data-loading]', $css);
        $this->assertStringContainsString('.fp-esqueleto', $css);
        $this->assertStringContainsString('.fp-tarjeta:has(> table)', $css);
        $ultimo = substr($css, strrpos($css, 'Pulido UX global'));
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $ultimo);
    }
}
