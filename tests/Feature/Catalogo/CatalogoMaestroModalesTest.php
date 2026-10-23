<?php

namespace Tests\Feature\Catalogo;

use App\Models\Linea;
use App\Models\Marca;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * Crear y editar en el catálogo maestro se hace en modales sobre la misma
 * pantalla: la lista, el buscador y las miniaturas se quedan detrás.
 */
class CatalogoMaestroModalesTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdminGeneral(): Usuario
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $admin = $this->adminGeneral();
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return $admin;
    }

    public function test_el_alta_se_abre_en_un_modal_accesible_y_se_cierra_al_guardar(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.catalogo-maestro')
            ->assertDontSeeHtml('data-alta-catalogo="linea"')
            ->call('abrirCrear', 'linea')
            ->assertSet('creando', 'linea')
            ->assertSeeHtml('data-alta-catalogo="linea"')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSeeHtml('keydown.escape.window="$wire.cerrarCrear()"')
            ->set('linea_nombre', 'Línea desde modal')
            ->call('guardarLinea')
            ->assertHasNoErrors()
            ->assertSet('creando', '')
            ->assertDontSeeHtml('data-alta-catalogo="linea"')
            ->assertSee('Línea desde modal');

        $this->assertTrue(Linea::where('nombre', 'Línea desde modal')->exists());
    }

    public function test_si_la_validacion_falla_el_modal_sigue_abierto(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'productos')
            ->call('abrirCrear', 'producto')
            ->call('guardarProducto')
            ->assertHasErrors(['producto_marca_id', 'producto_codigo'])
            ->assertSet('creando', 'producto')
            ->assertSee('Elige la marca.');
    }

    public function test_cerrar_el_alta_no_guarda_nada(): void
    {
        $this->comoAdminGeneral();
        $antes = Marca::count();

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->call('abrirCrear', 'marca')
            ->set('marca_nombre', 'Marca a medias')
            ->call('cerrarCrear')
            ->assertSet('creando', '')
            ->assertDontSeeHtml('data-alta-catalogo');

        $this->assertSame($antes, Marca::count());
    }

    public function test_editar_abre_un_modal_y_no_deja_el_alta_abierta(): void
    {
        $this->comoAdminGeneral();
        $marca = Marca::create(['nombre' => 'Marca modal', 'activa' => true]);

        Livewire::test('admin.catalogo-maestro')
            ->set('pestana', 'marcas')
            ->call('abrirCrear', 'marca')
            ->call('editar', 'marca', $marca->id)
            ->assertSet('creando', '')
            ->assertSeeHtml('data-edicion-catalogo')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSeeHtml('keydown.escape.window="$wire.cancelarEdicion()"')
            ->set('editar_nombre', 'Marca modal editada')
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertDontSeeHtml('data-edicion-catalogo');

        $this->assertSame('Marca modal editada', $marca->fresh()->nombre);
    }

    public function test_un_tipo_desconocido_no_abre_nada(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.catalogo-maestro')
            ->call('abrirCrear', 'usuario')
            ->assertStatus(404);
    }
}
