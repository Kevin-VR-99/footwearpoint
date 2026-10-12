<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Distribuidora\ActualizarAdminDistribuidoraAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-194 — El admin general edita los datos de una distribuidora y de su
 * administrador desde /admin.
 */
class EditarDistribuidoraAdminGeneralTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    private Distribuidora $distribuidora;

    private Usuario $adminTienda;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => \Database\Seeders\AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();

        $this->actingAs($this->adminGeneral);

        $this->distribuidora = app(CrearDistribuidoraAction::class)->ejecutar(
            ['nombre_comercial' => 'Zapatería Norte', 'slug' => 'zapateria-norte', 'subdominio' => 'zapateria-norte'],
            ['nombre' => 'Laura Méndez', 'email' => 'laura@zapaterianorte.test', 'password' => 'clave-original-1'],
            true,
        );
        $this->adminTienda = Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail();
        $this->adminTienda->forceFill(['email_verified_at' => now()->subDay()])->save();
    }

    private function editar(array $cambios = []): Testable
    {
        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirEdicion', $this->distribuidora->id);

        foreach ($cambios as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente->call('guardarEdicion');
    }

    public function test_abre_el_formulario_con_los_datos_actuales(): void
    {
        Livewire::test('admin.distribuidoras-index')
            ->assertSee('Editar')
            ->call('abrirEdicion', $this->distribuidora->id)
            ->assertSet('editar_nombre_comercial', 'Zapatería Norte')
            ->assertSet('editar_slug', 'zapateria-norte')
            ->assertSet('editar_admin_email', 'laura@zapaterianorte.test')
            ->assertSet('editarTieneAdmin', true)
            ->assertSee('Editar distribuidora');
    }

    public function test_edita_los_datos_de_la_distribuidora_y_su_admin(): void
    {
        $this->editar([
            'editar_nombre_comercial' => 'Zapatería Norte Centro',
            'editar_razon_social' => 'Zapatería Norte S.A. de C.V.',
            'editar_rfc' => 'zno010101ab1',
            'editar_slug' => 'zapateria-norte-centro',
            'editar_subdominio' => 'NorteCentro',
            'editar_email_publico' => 'hola@norte.test',
            'editar_telefono_publico' => '5551234567',
            'editar_direccion_publica' => 'Av. Juárez 10',
            'editar_descripcion_publica' => 'Calzado para toda la familia',
            'editar_horario_publico' => 'Lunes a sábado 9:00–19:00',
            'editar_admin_nombre' => 'Laura Méndez Ruiz',
            'editar_admin_email' => 'laura.mendez@norte.test',
        ])->assertHasNoErrors()->assertSet('distribuidoraEdicionId', null);

        $d = $this->distribuidora->fresh();
        $this->assertSame('Zapatería Norte Centro', $d->nombre_comercial);
        $this->assertSame('ZNO010101AB1', $d->rfc);
        $this->assertSame('zapateria-norte-centro', $d->slug);
        $this->assertSame('nortecentro', $d->subdominio);
        $this->assertSame('hola@norte.test', $d->email_publico);
        $this->assertSame('Lunes a sábado 9:00–19:00', $d->horario_publico);

        $admin = $this->adminTienda->fresh();
        $this->assertSame('Laura Méndez Ruiz', $admin->nombre);
        $this->assertSame('laura.mendez@norte.test', $admin->email);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('clave-original-1', $admin->password));
    }

    public function test_valida_slug_subdominio_y_correo_duplicados(): void
    {
        $otra = app(CrearDistribuidoraAction::class)->ejecutar(
            ['nombre_comercial' => 'Calzado Sur', 'slug' => 'calzado-sur', 'subdominio' => 'sur-tienda'],
            ['nombre' => 'Pedro', 'email' => 'pedro@sur.test', 'password' => 'clave-sur-123'],
            true,
        );

        $this->editar([
            'editar_slug' => 'calzado-sur',
            'editar_subdominio' => 'sur-tienda',
            'editar_admin_email' => 'pedro@sur.test',
        ])->assertHasErrors(['editar_slug' => 'Ese slug ya lo usa otra distribuidora.'])
            ->assertHasErrors(['editar_subdominio' => 'Ese subdominio ya lo usa otra distribuidora.']);

        // El subdominio no puede ser el slug de otra.
        $this->editar(['editar_subdominio' => 'calzado-sur'])
            ->assertHasErrors(['editar_subdominio' => 'Ese subdominio ya lo usa otra distribuidora.']);

        // Con la distribuidora bien, el correo repetido del admin se rechaza.
        $this->editar(['editar_admin_email' => 'pedro@sur.test'])
            ->assertHasErrors(['editar_admin_email' => 'Ese correo ya tiene una cuenta.']);

        $this->assertSame('zapateria-norte', $this->distribuidora->fresh()->slug);
        $this->assertSame('laura@zapaterianorte.test', $this->adminTienda->fresh()->email);
        $this->assertSame('calzado-sur', $otra->fresh()->slug);
    }

    public function test_guardar_sin_cambiar_slug_subdominio_ni_correo_no_choca_consigo_misma(): void
    {
        $this->editar(['editar_telefono_publico' => '5550000000'])->assertHasNoErrors();
        $this->assertSame('5550000000', $this->distribuidora->fresh()->telefono_publico);
    }

    public function test_rechaza_subdominio_reservado_o_con_formato_invalido(): void
    {
        $this->editar(['editar_subdominio' => 'admin'])
            ->assertHasErrors(['editar_subdominio' => 'Ese subdominio está reservado. Elige otro.']);

        $this->editar(['editar_subdominio' => '-mal_'])->assertHasErrors('editar_subdominio');

        $this->assertSame('zapateria-norte', $this->distribuidora->fresh()->subdominio);
    }

    public function test_cambia_la_contrasena_y_puede_entrar_con_ella(): void
    {
        $this->editar([
            'editar_admin_password' => 'clave-nueva-2026',
            'editar_admin_password_confirmation' => 'clave-nueva-2026',
        ])->assertHasNoErrors();

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => 'laura@zapaterianorte.test', 'password' => 'clave-nueva-2026']));
        Auth::logout();
        $this->assertFalse(Auth::attempt(['email' => 'laura@zapaterianorte.test', 'password' => 'clave-original-1']));
    }

    public function test_valida_largo_y_confirmacion_de_la_contrasena(): void
    {
        $this->editar([
            'editar_admin_password' => 'corta',
            'editar_admin_password_confirmation' => 'corta',
        ])->assertHasErrors(['editar_admin_password' => 'La contraseña debe tener al menos 8 caracteres.']);

        $this->editar([
            'editar_admin_password' => 'clave-nueva-2026',
            'editar_admin_password_confirmation' => 'otra-distinta',
        ])->assertHasErrors(['editar_admin_password' => 'La confirmación no coincide con la contraseña.']);

        $this->assertTrue(Hash::check('clave-original-1', $this->adminTienda->fresh()->password));
    }

    public function test_contrasena_vacia_no_cambia(): void
    {
        $antes = $this->adminTienda->fresh()->password;

        $this->editar(['editar_admin_nombre' => 'Laura M.'])->assertHasNoErrors();

        $this->assertSame($antes, $this->adminTienda->fresh()->password);
        $this->assertSame('Laura M.', $this->adminTienda->fresh()->nombre);
    }

    public function test_queda_en_auditoria_sin_la_contrasena(): void
    {
        $this->editar([
            'editar_nombre_comercial' => 'Zapatería Norte Centro',
            'editar_admin_email' => 'laura.mendez@norte.test',
            'editar_admin_password' => 'clave-nueva-2026',
            'editar_admin_password_confirmation' => 'clave-nueva-2026',
        ])->assertHasNoErrors();

        $datos = Auditoria::withoutGlobalScopes()
            ->where('accion', 'distribuidora.datos_actualizados')->firstOrFail();
        $this->assertSame($this->distribuidora->id, (int) $datos->entidad_id);
        $this->assertSame($this->distribuidora->id, (int) $datos->distribuidora_id);
        $this->assertSame($this->adminGeneral->id, (int) $datos->usuario_id);
        $this->assertSame(['nombre_comercial' => 'Zapatería Norte'], $datos->datos_previos);
        $this->assertSame(['nombre_comercial' => 'Zapatería Norte Centro'], $datos->datos_nuevos);

        $admin = Auditoria::withoutGlobalScopes()
            ->where('accion', ActualizarAdminDistribuidoraAction::ACCION_AUDITORIA)->firstOrFail();
        $this->assertSame(['email' => 'laura@zapaterianorte.test'], $admin->datos_previos);
        $this->assertSame(['email' => 'laura.mendez@norte.test', 'password_restablecida' => true], $admin->datos_nuevos);

        // Ni la contraseña ni su hash quedan en ninguna parte de la auditoría.
        $hash = $this->adminTienda->fresh()->password;
        foreach (Auditoria::withoutGlobalScopes()->get() as $registro) {
            $texto = json_encode([$registro->datos_previos, $registro->datos_nuevos]);
            $this->assertStringNotContainsString('clave-nueva-2026', $texto);
            $this->assertStringNotContainsString($hash, $texto);
        }
    }

    public function test_sin_cambios_no_deja_auditoria(): void
    {
        $this->editar()->assertHasNoErrors();

        $this->assertSame(0, Auditoria::withoutGlobalScopes()->where('accion', 'like', 'distribuidora.%')->count());
    }

    public function test_si_no_tiene_administrador_lo_dice_y_guarda_la_distribuidora(): void
    {
        Tenant::forzar($this->distribuidora->id, fn () => DistribuidoraStaff::where('distribuidora_id', $this->distribuidora->id)->delete());

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirEdicion', $this->distribuidora->id)
            ->assertSet('editarTieneAdmin', false)
            ->assertSee(ActualizarAdminDistribuidoraAction::MENSAJE_SIN_ADMIN)
            ->set('editar_nombre_comercial', 'Sin Admin')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertSame('Sin Admin', $this->distribuidora->fresh()->nombre_comercial);
    }

    public function test_un_usuario_que_no_es_admin_general_recibe_403(): void
    {
        Tenant::olvidarCache();
        $this->actingAs($this->adminTienda);

        Livewire::test('admin.distribuidoras-index')->assertForbidden();
        $this->get('/admin')->assertForbidden();

        $this->assertSame('Zapatería Norte', $this->distribuidora->fresh()->nombre_comercial);
    }
}
