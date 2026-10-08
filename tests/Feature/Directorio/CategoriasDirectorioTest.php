<?php

namespace Tests\Feature\Directorio;

use App\Exceptions\RespuestaErrorApi;
use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Directorio\AsignarCategoriasDirectorioAction;
use App\Services\Directorio\GuardarCategoriaDirectorioAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\CategoriaDirectorioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-197 (G16) — Categorías generales del directorio.
 *
 * E2-05: "Como administrador general, quiero configurar los parámetros
 * generales del marketplace … Puedo definir categorías generales del
 * directorio." Solo el admin general las crea, edita, activa/desactiva y
 * asigna; la distribuidora solo las ve en su perfil.
 */
class CategoriasDirectorioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN_DISTRIBUIDORA = 'admin@calzadosramirez.test';

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->adminGeneral = Usuario::create([
            'nombre'   => 'Admin General',
            'email'    => 'admin.general@footwearpoint.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function categoria(string $nombre): CategoriaDirectorio
    {
        return CategoriaDirectorio::where('nombre', $nombre)->firstOrFail();
    }

    /** @return list<string> nombres de sus categorías (activas e inactivas), por nombre. */
    private function nombresAsignados(): array
    {
        return $this->distribuidora()->categoriasDirectorio()->orderBy('nombre')->pluck('nombre')->all();
    }

    private function adminDistribuidora(): Usuario
    {
        return Usuario::where('email', self::ADMIN_DISTRIBUIDORA)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Datos de ejemplo
    // ---------------------------------------------------------------

    public function test_el_seeder_crea_las_seis_categorias_y_las_de_la_demo_sin_duplicar(): void
    {
        $this->seed(CategoriaDirectorioSeeder::class);

        $this->assertSame(
            ['Caballero', 'Casual', 'Dama', 'Deportivo', 'Escolar', 'Infantil'],
            CategoriaDirectorio::orderBy('nombre')->pluck('nombre')->all()
        );
        $this->assertSame(6, CategoriaDirectorio::where('activa', true)->count());
        $this->assertSame(['Caballero', 'Casual', 'Dama'], $this->nombresAsignados());
    }

    // ---------------------------------------------------------------
    // Panel del admin general
    // ---------------------------------------------------------------

    public function test_el_admin_general_crea_edita_desactiva_y_activa_desde_el_panel(): void
    {
        $this->actingAs($this->adminGeneral);

        $componente = Livewire::test('admin.categorias-directorio-index')
            ->call('nuevo')
            ->set('nombre', '  Calzado   de seguridad ')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Categoría «Calzado de seguridad» creada.')
            ->assertSet('mostrarForm', false);

        $nueva = $this->categoria('Calzado de seguridad');
        $this->assertTrue($nueva->activa);

        $componente->call('editar', $nueva->id)
            ->set('nombre', 'Seguridad industrial')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Categoría «Seguridad industrial» actualizada.');

        $componente->call('desactivar', $nueva->id)
            ->assertSet('mensaje', 'Categoría «Seguridad industrial» desactivada. Ya no se muestra en el marketplace.');
        $this->assertFalse($nueva->fresh()->activa);

        $componente->call('activar', $nueva->id)
            ->assertSet('mensaje', 'Categoría «Seguridad industrial» activada.');
        $this->assertTrue($nueva->fresh()->activa);

        $componente->assertSee('Seguridad industrial')->assertSee('Dama');
    }

    public function test_el_nombre_es_obligatorio_y_no_se_repite_sin_importar_mayusculas_ni_acentos(): void
    {
        $this->actingAs($this->adminGeneral);

        $componente = Livewire::test('admin.categorias-directorio-index')
            ->call('nuevo')
            ->set('nombre', '   ')
            ->call('guardar');
        $this->assertSame(GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_OBLIGATORIO, $componente->errors()->first('nombre'));

        foreach (['dama', 'DAMA', 'Dáma'] as $repetido) {
            $componente->set('nombre', $repetido)->call('guardar');
            $this->assertSame(GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_REPETIDO, $componente->errors()->first('nombre'));
        }

        $componente->set('nombre', str_repeat('a', 121))->call('guardar');
        $this->assertSame(GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_LARGO, $componente->errors()->first('nombre'));

        $this->assertSame(6, CategoriaDirectorio::count());

        // Renombrarla a su mismo nombre no cuenta como repetido.
        $dama = $this->categoria('Dama');
        $componente->call('editar', $dama->id)->set('nombre', 'Dama')->call('guardar')->assertHasNoErrors();
    }

    public function test_solo_el_admin_general_entra_a_la_pantalla(): void
    {
        $this->get(route('admin.categorias-directorio'))->assertRedirect(route('login'));

        $this->actingAs($this->adminDistribuidora())
            ->get(route('admin.categorias-directorio'))
            ->assertForbidden();

        Livewire::test('admin.categorias-directorio-index')->assertStatus(403);

        $this->actingAs($this->adminGeneral)
            ->get(route('admin.categorias-directorio'))
            ->assertOk()
            ->assertSee('Categorías del directorio');
    }

    // ---------------------------------------------------------------
    // Asignar desde "Ver datos"
    // ---------------------------------------------------------------

    public function test_ver_datos_marca_sus_categorias_y_las_reemplaza_al_guardar(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();

        $componente = Livewire::test('admin.distribuidoras-index')->call('verDatos', $distribuidora->id);

        $marcadas = $componente->get('categoriasSeleccionadas');
        sort($marcadas);
        $esperadas = array_map('strval', [$this->categoria('Dama')->id, $this->categoria('Caballero')->id, $this->categoria('Casual')->id]);
        sort($esperadas);
        $this->assertSame($esperadas, $marcadas);

        $componente->assertSee('Categorías del directorio')->assertSee('Infantil');

        $componente->set('categoriasSeleccionadas', [(string) $this->categoria('Infantil')->id, (string) $this->categoria('Escolar')->id])
            ->call('guardarCategorias')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Categorías de «Calzados Ramírez» guardadas.');

        $this->assertSame(['Escolar', 'Infantil'], $this->nombresAsignados());

        $componente->set('categoriasSeleccionadas', [])->call('guardarCategorias')->assertHasNoErrors();
        $this->assertSame([], $this->nombresAsignados());
    }

    public function test_las_inactivas_que_ya_tenia_se_conservan_y_se_avisan(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $this->categoria('Casual')->update(['activa' => false]);

        $componente = Livewire::test('admin.distribuidoras-index')->call('verDatos', $distribuidora->id);

        $this->assertNotContains((string) $this->categoria('Casual')->id, $componente->get('categoriasSeleccionadas'));
        $componente->assertSee('También tiene categorías inactivas');

        $componente->set('categoriasSeleccionadas', [(string) $this->categoria('Dama')->id])
            ->call('guardarCategorias')
            ->assertHasNoErrors();

        $this->assertSame(['Casual', 'Dama'], $this->nombresAsignados());
    }

    public function test_no_se_puede_asignar_una_inactiva_ni_una_que_no_existe(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $infantil = $this->categoria('Infantil');
        $infantil->update(['activa' => false]);

        foreach ([(string) $infantil->id, '999999', 'abc'] as $id) {
            $componente = Livewire::test('admin.distribuidoras-index')
                ->call('verDatos', $distribuidora->id)
                ->set('categoriasSeleccionadas', [$id])
                ->call('guardarCategorias');

            $this->assertSame(AsignarCategoriasDirectorioAction::MENSAJE_NO_DISPONIBLE, $componente->errors()->first('categoriasSeleccionadas'));
        }

        $this->assertSame(['Caballero', 'Casual', 'Dama'], $this->nombresAsignados());
    }

    // ---------------------------------------------------------------
    // API del admin general
    // ---------------------------------------------------------------

    public function test_api_crea_edita_y_cambia_el_estado(): void
    {
        Sanctum::actingAs($this->adminGeneral);

        $id = $this->postJson('/api/admin/categorias-directorio', ['nombre' => 'Botas'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Botas')
            ->assertJsonPath('data.activa', true)
            ->assertJsonPath('data.total_distribuidoras', 0)
            ->assertJsonPath('message', 'Categoría creada correctamente.')
            ->json('data.id');

        $this->postJson('/api/admin/categorias-directorio', ['nombre' => 'botas'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_REPETIDO);

        $this->postJson('/api/admin/categorias-directorio', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', GuardarCategoriaDirectorioAction::MENSAJE_NOMBRE_OBLIGATORIO);

        $this->putJson("/api/admin/categorias-directorio/{$id}", ['nombre' => 'Botas y botines'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Botas y botines')
            ->assertJsonPath('data.activa', true);

        $this->patchJson("/api/admin/categorias-directorio/{$id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.activa', false)
            ->assertJsonPath('message', 'Categoría desactivada correctamente.');

        $this->patchJson("/api/admin/categorias-directorio/{$id}/activar")
            ->assertOk()
            ->assertJsonPath('data.activa', true);

        $dama = $this->getJson('/api/admin/categorias-directorio')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->collect('data')
            ->firstWhere('nombre', 'Dama');
        $this->assertSame(1, $dama['total_distribuidoras']);

        $this->putJson('/api/admin/categorias-directorio/999999', ['nombre' => 'X'])
            ->assertNotFound()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_ENCONTRADO]);
    }

    public function test_api_asigna_categorias_a_una_distribuidora(): void
    {
        Sanctum::actingAs($this->adminGeneral);
        $url = "/api/admin/distribuidoras/{$this->distribuidora()->id}/categorias-directorio";
        $infantil = $this->categoria('Infantil');

        $this->putJson($url, ['categorias' => [$infantil->id, $this->categoria('Dama')->id]])
            ->assertOk()
            ->assertJsonPath('data.categorias.0.nombre', 'Dama')
            ->assertJsonPath('data.categorias.1.nombre', 'Infantil')
            ->assertJsonPath('message', 'Categorías de la distribuidora guardadas correctamente.');

        $this->assertSame(['Dama', 'Infantil'], $this->nombresAsignados());

        $infantil->update(['activa' => false]);
        $this->putJson($url, ['categorias' => [$infantil->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.categorias.0', AsignarCategoriasDirectorioAction::MENSAJE_NO_DISPONIBLE);

        $this->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors('categorias');

        // Lista vacía: quita las activas; la inactiva que tenía se conserva.
        $this->putJson($url, ['categorias' => []])->assertOk();
        $this->assertSame(['Infantil'], $this->nombresAsignados());

        $this->getJson("/api/admin/distribuidoras/{$this->distribuidora()->id}")
            ->assertOk()
            ->assertJsonPath('data.categorias_directorio.0.nombre', 'Infantil')
            ->assertJsonPath('data.categorias_directorio.0.activa', false);
    }

    public function test_api_solo_para_el_admin_general(): void
    {
        $id = $this->distribuidora()->id;

        $this->getJson('/api/admin/categorias-directorio')->assertUnauthorized();

        Sanctum::actingAs($this->adminDistribuidora());

        $this->getJson('/api/admin/categorias-directorio')->assertForbidden();
        $this->postJson('/api/admin/categorias-directorio', ['nombre' => 'Intrusa'])->assertForbidden();
        $this->putJson("/api/admin/distribuidoras/{$id}/categorias-directorio", ['categorias' => []])->assertForbidden();

        $this->assertFalse(CategoriaDirectorio::where('nombre', 'Intrusa')->exists());
        $this->assertSame(['Caballero', 'Casual', 'Dama'], $this->nombresAsignados());
    }

    // ---------------------------------------------------------------
    // Perfil de la distribuidora: solo las ve
    // ---------------------------------------------------------------

    public function test_la_distribuidora_ve_sus_categorias_activas_en_su_perfil(): void
    {
        $this->categoria('Casual')->update(['activa' => false]);
        $this->actingAs($this->adminDistribuidora());

        Livewire::test('distribuidora.perfil')
            ->assertSet('categoriasDirectorio', ['Caballero', 'Dama'])
            ->assertSee('Las asigna FootwearPoint')
            ->assertDontSeeHtml('wire:model="categoriasDirectorio"');
    }
}
