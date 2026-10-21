<?php

namespace Tests\Feature\Funciones;

use App\Models\Distribuidora;
use App\Models\FiltroFavorito;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Filtros favoritos de Reportes: guardar, aplicar, renombrar, eliminar; por usuario y distribuidora. */
class FiltrosFavoritosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $u = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($u);
        Tenant::olvidarCache();

        return $u;
    }

    public function test_la_migracion_es_idempotente(): void
    {
        $migracion = require database_path('migrations/2026_10_21_100200_crea_filtros_favoritos.php');
        $migracion->up();
        $this->assertTrue(Schema::hasTable('filtros_favoritos'));
    }

    public function test_guardar_aplicar_renombrar_y_eliminar(): void
    {
        $admin = $this->como('admin@calzadosramirez.test');

        $c = Livewire::test('reportes.index')
            ->set('desde', '2026-10-01')->set('hasta', '2026-10-31')->set('estado', 'colocado')
            ->set('nombreFavorito', 'Octubre colocados')->call('guardarFavorito')
            ->assertHasNoErrors()->assertDispatched('guardado')
            ->assertSee('data-favorito="Octubre colocados"', false);

        $fav = FiltroFavorito::query()->firstOrFail();
        $this->assertSame($admin->id, $fav->usuario_id);
        $this->assertSame(Tenant::id(), $fav->distribuidora_id);
        $this->assertSame('colocado', $fav->filtros['estado']);

        // Nombre repetido.
        $c->set('nombreFavorito', 'Octubre colocados')->call('guardarFavorito')->assertHasErrors('nombreFavorito');

        // Aplicar con un clic desde filtros limpios.
        $c->call('limpiarFiltros')->assertSet('estado', '')
            ->call('aplicarFavorito', $fav->id)
            ->assertSet('desde', '2026-10-01')->assertSet('hasta', '2026-10-31')->assertSet('estado', 'colocado');

        $c->call('renombrarFavorito', $fav->id)->set('nombreRenombrar', 'Mes de octubre')->call('guardarRenombre')
            ->assertSet('renombrandoFavoritoId', null);
        $this->assertSame('Mes de octubre', $fav->fresh()->nombre);

        $c->call('eliminarFavorito', $fav->id);
        $this->assertDatabaseMissing('filtros_favoritos', ['id' => $fav->id]);
    }

    public function test_cada_usuario_ve_solo_los_suyos(): void
    {
        $this->como('admin@calzadosramirez.test');
        Livewire::test('reportes.index')->set('estado', 'entregado')->set('nombreFavorito', 'Del admin')->call('guardarFavorito');
        $fav = FiltroFavorito::query()->firstOrFail();

        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        $empleado->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();
        $this->como('empleado@calzadosramirez.test');

        $c = Livewire::test('reportes.index')->assertDontSee('data-favorito="Del admin"', false);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $c->instance()->aplicarFavorito($fav->id);
    }

    public function test_otra_distribuidora_no_alcanza_el_favorito(): void
    {
        $this->como('admin@calzadosramirez.test');
        Livewire::test('reportes.index')->set('nombreFavorito', 'Ramírez')->call('guardarFavorito');
        $fav = FiltroFavorito::query()->firstOrFail();

        $this->como('admin@boutiquedelcalzado.test');
        $this->assertSame(Distribuidora::where('slug', 'boutique-del-calzado')->value('id'), Tenant::id());
        $c = Livewire::test('reportes.index')->assertDontSee('data-favorito="Ramírez"', false);

        foreach (['aplicarFavorito', 'renombrarFavorito', 'eliminarFavorito'] as $metodo) {
            try {
                $c->instance()->{$metodo}($fav->id);
                $this->fail("$metodo alcanzó un favorito ajeno.");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            }
        }
        $this->assertDatabaseHas('filtros_favoritos', ['id' => $fav->id, 'nombre' => 'Ramírez']);
    }

    public function test_un_filtro_manipulado_no_rompe_la_pantalla(): void
    {
        $admin = $this->como('admin@calzadosramirez.test');
        $fav = FiltroFavorito::create(['usuario_id' => $admin->id, 'nombre' => 'Raro', 'filtros' => ['estado' => ['x'], 'otro' => 'y', 'cicloId' => 5]]);

        Livewire::test('reportes.index')->call('aplicarFavorito', $fav->id)
            ->assertSet('estado', '')->assertSet('cicloId', '5')->assertOk();
    }
}
