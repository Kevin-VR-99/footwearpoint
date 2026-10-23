<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Borrar definitivamente" deja en auditorias quien borro, cuando y que se
 * fue, y esa fila sobrevive al borrado de la distribuidora.
 */
class AuditoriaBorrarDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_borrar_definitivamente_deja_constancia_que_sobrevive(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ramirez = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $pedidos = DB::table('pedidos')->where('distribuidora_id', $ramirez->id)->count();
        $sucursales = DB::table('sucursales')->where('distribuidora_id', $ramirez->id)->count();

        $this->actingAs($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $ramirez->id)
            ->call('pedirBorrado')
            ->call('eliminarDistribuidora');

        $this->assertDatabaseMissing('distribuidoras', ['id' => $ramirez->id]);

        $registro = Auditoria::where('accion', 'distribuidora.borrada_definitivamente')
            ->where('entidad_id', $ramirez->id)
            ->sole();

        $this->assertSame('distribuidora', $registro->entidad_tipo);
        $this->assertSame($admin->id, $registro->usuario_id);
        $this->assertSame($ramirez->id, $registro->datos_previos['distribuidora']['id']);
        $this->assertSame('calzados-ramirez', $registro->datos_previos['distribuidora']['slug']);
        $this->assertSame($ramirez->nombre_comercial, $registro->datos_previos['distribuidora']['nombre_comercial']);
        $this->assertSame($admin->id, $registro->datos_previos['borrada_por']['id']);
        $this->assertSame($admin->email, $registro->datos_previos['borrada_por']['email']);
        $this->assertNotEmpty($registro->datos_previos['borrada_en']);
        $this->assertSame($pedidos, $registro->datos_previos['conteos']['pedidos']);
        $this->assertSame($sucursales, $registro->datos_previos['conteos']['sucursales']);
        $this->assertArrayHasKey('usuarios_vinculados', $registro->datos_previos['conteos']);
    }
}