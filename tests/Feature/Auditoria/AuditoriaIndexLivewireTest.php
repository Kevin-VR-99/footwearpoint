<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-160 — UI de auditoría (E17-03) en el panel.
 */
class AuditoriaIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

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

    public function test_el_admin_ve_la_bitacora_de_auditoria(): void
    {
        $admin = $this->como(self::ADMIN);

        Auditoria::create([
            'usuario_id' => $admin->id,
            'distribuidora_id' => Tenant::id(),
            'accion' => 'pedido.cambio_estado',
            'entidad_tipo' => 'pedido',
            'entidad_id' => 99,
            'datos_previos' => ['estado' => 'colocado'],
            'datos_nuevos' => ['estado' => 'listo_entrega'],
            'ip_origen' => '127.0.0.1',
        ]);

        Livewire::test('auditoria.index')
            ->assertOk()
            ->assertSee('Auditoría')
            ->assertSee('pedido.cambio_estado')
            ->assertSee('pedido');
    }

    public function test_el_empleado_no_puede_abrir_auditoria(): void
    {
        $this->como(self::EMPLEADO);

        Livewire::test('auditoria.index')
            ->assertForbidden();
    }

    public function test_el_filtro_por_accion_reduce_los_resultados(): void
    {
        $admin = $this->como(self::ADMIN);

        Auditoria::create([
            'usuario_id' => $admin->id,
            'distribuidora_id' => Tenant::id(),
            'accion' => 'pedido.cambio_estado',
            'entidad_tipo' => 'pedido',
            'entidad_id' => 1,
            'ip_origen' => '127.0.0.1',
        ]);
        Auditoria::create([
            'usuario_id' => $admin->id,
            'distribuidora_id' => Tenant::id(),
            'accion' => 'pago.anticipo',
            'entidad_tipo' => 'pago',
            'entidad_id' => 2,
            'ip_origen' => '127.0.0.1',
        ]);

        Livewire::test('auditoria.index')
            ->set('filtro_accion', 'pago.anticipo')
            ->assertSee('pago.anticipo')
            ->assertSee('#2')
            ->assertDontSee('>#1</span>');
    }
}
