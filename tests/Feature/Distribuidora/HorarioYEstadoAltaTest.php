<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Support\HorarioPublico;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Editor de horario público, selector de estado inicial y confirmación
 * de contraseña en el alta del admin general.
 */
class HorarioYEstadoAltaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();

        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($this->adminGeneral);
    }

    /** @param  array<string, mixed>  $cambios */
    private function crear(array $cambios = []): \Livewire\Features\SupportTesting\Testable
    {
        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            ->set('nuevo_nombre_comercial', $cambios['nuevo_nombre_comercial'] ?? 'Zapatería Norte');

        unset($cambios['nuevo_nombre_comercial']);

        $password = $cambios['admin_password'] ?? 'clave-segura-1';
        $datos = array_merge([
            'nuevo_estado_inicial' => 'activa_visible',
            'admin_nombre' => 'Laura Méndez',
            'admin_email' => 'laura@zapaterianorte.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $cambios['admin_password_confirmation'] ?? $password,
            'nuevo_horarios_publicos' => [
                HorarioPublico::grupo(['lun', 'mar', 'mie', 'jue', 'vie'], [['09:00', '14:00'], ['16:00', '20:00']]),
                HorarioPublico::grupo(['sab'], [['10:00', '14:00']]),
                HorarioPublico::grupo(['dom'], [], true),
            ],
        ], $cambios);

        foreach ($datos as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente->call('crearDistribuidora');
    }

    public function test_guarda_el_horario_estructurado_y_el_texto_de_exhibicion(): void
    {
        $this->crear()->assertHasNoErrors();

        $d = Distribuidora::where('slug', 'zapateria-norte')->firstOrFail();
        $this->assertSame('Lun–Vie 9:00–14:00 y 16:00–20:00 · Sáb 10:00–14:00 · Dom cerrado', $d->horario_publico);
        $this->assertSame(['lun', 'mar', 'mie', 'jue', 'vie'], $d->horarios_publicos[0]['dias']);
        $this->assertTrue($d->marketplace_visible);
        $this->assertSame('activa', $d->estado);
        $this->assertSame(1, $d->suscripciones()->where('estado', 'activa')->count());
    }

    public function test_rechaza_rangos_que_se_traslapan(): void
    {
        $this->crear([
            'nuevo_horarios_publicos' => [
                HorarioPublico::grupo(['lun'], [['09:00', '14:00'], ['13:00', '18:00']]),
            ],
        ])->assertHasErrors('nuevo_horario_publico');

        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_activa_oculta_entra_pero_no_sale_en_el_directorio(): void
    {
        $this->crear(['nuevo_estado_inicial' => 'activa_oculta'])->assertHasNoErrors();

        $d = Distribuidora::where('slug', 'zapateria-norte')->firstOrFail();
        $this->assertSame('activa', $d->estado);
        $this->assertFalse((bool) $d->marketplace_visible);
        $this->assertSame(1, $d->suscripciones()->where('estado', 'activa')->count());
    }

    public function test_pendiente_no_queda_visible_aunque_antes_se_hubiera_marcado(): void
    {
        $this->crear(['nuevo_estado_inicial' => 'pendiente'])->assertHasNoErrors();

        $d = Distribuidora::where('slug', 'zapateria-norte')->firstOrFail();
        $this->assertSame('pendiente', $d->estado);
        $this->assertFalse((bool) $d->marketplace_visible);
        $this->assertSame(0, $d->suscripciones()->count());
    }

    public function test_pide_confirmar_la_contrasena(): void
    {
        $this->crear([
            'admin_password' => 'clave-segura-1',
            'admin_password_confirmation' => 'clave-segura-2',
        ])->assertHasErrors(['admin_password']);

        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_editar_conserva_un_horario_solo_texto(): void
    {
        $d = Distribuidora::query()->firstOrFail();
        $d->update(['horario_publico' => 'Lunes a sábado, 9:00 a 19:00', 'horarios_publicos' => null]);

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirEdicion', $d->id)
            ->assertSet('editar_horario_publico', 'Lunes a sábado, 9:00 a 19:00')
            ->set('editar_horarios_publicos', [])
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $d->refresh();
        $this->assertSame('Lunes a sábado, 9:00 a 19:00', $d->horario_publico);
        $this->assertNull($d->horarios_publicos);
    }
}
