<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Auditoria;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\CodigoInternoRevendedor;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantallas Empleados y Clientes mayoristas: modales, fotos de perfil,
 * código interno automático y «Eliminar definitivamente».
 */
class PerfilesEmpleadosRevendedoresTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Mail::fake();
        Storage::fake('s3');

        $this->admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function distribuidoraId(): int
    {
        return (int) DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $this->admin->id)->value('distribuidora_id');
    }

    private function afiliar(string $nombre, array $extra = []): RevendedorDistribuidora
    {
        $pantalla = Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', $nombre);

        foreach ($extra as $campo => $valor) {
            $pantalla->set($campo, $valor);
        }

        $pantalla->call('guardarRevendedor')->assertHasNoErrors();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->withoutGlobalScopes()->where('nombre', $nombre))
            ->latest('id')->firstOrFail();
    }

    private function crearPedido(array $datos): void
    {
        Tenant::forzar($this->distribuidoraId(), fn () => \App\Models\Pedido::create($datos + [
            'distribuidora_id' => $this->distribuidoraId(),
            'sucursal_id' => \App\Models\Sucursal::withoutGlobalScopes()->where('distribuidora_id', $this->distribuidoraId())->value('id'),
            'folio' => 'PRUEBA-'.uniqid(),
            'estado' => 'colocado', 'subtotal' => 10, 'total' => 10, 'fecha_colocacion' => now(),
        ]));
    }

    // ---------------------------------------------------------------- Código interno

    public function test_el_codigo_interno_se_asigna_solo_y_es_consecutivo(): void
    {
        $siguiente = app(CodigoInternoRevendedor::class)->siguiente($this->distribuidoraId());
        $this->assertMatchesRegularExpression('/^REV-\d{3,}$/', $siguiente);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioAfiliarRevendedor')
            ->assertSet('revendedor_codigo_interno', $siguiente)
            ->assertSee('Se asigna al guardar');

        $uno = $this->afiliar('Primero Código');
        $dos = $this->afiliar('Segundo Código');

        $this->assertSame($siguiente, $uno->codigo_interno);
        $n = (int) substr($uno->codigo_interno, 4);
        $this->assertSame(sprintf('REV-%03d', $n + 1), $dos->codigo_interno);
    }

    public function test_el_codigo_interno_no_se_puede_escribir_a_mano(): void
    {
        $afiliacion = $this->afiliar('Sin Código Manual', ['revendedor_codigo_interno' => 'PIRATA']);
        $this->assertStringStartsWith('REV-', $afiliacion->codigo_interno);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioEditarRevendedor', $afiliacion->id)
            ->set('revendedor_codigo_interno', 'OTRO')
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        $this->assertSame($afiliacion->codigo_interno, $afiliacion->fresh()->codigo_interno);
    }

    public function test_el_codigo_es_por_distribuidora(): void
    {
        $otra = \App\Models\Distribuidora::create([
            'nombre_comercial' => 'Calzado Vacío', 'slug' => 'calzado-vacio-'.uniqid(),
            'estado' => 'activa', 'fecha_solicitud' => now(), 'fecha_aprobacion' => now(),
        ]);

        $this->assertSame('REV-001', app(CodigoInternoRevendedor::class)->siguiente($otra->id));
    }

    public function test_la_migracion_rellena_los_codigos_vacios(): void
    {
        $id = $this->distribuidoraId();
        $afiliacion = $this->afiliar('Sin Código Antiguo');
        DB::table('revendedor_distribuidora')->where('id', $afiliacion->id)->update(['codigo_interno' => null]);

        $migracion = require database_path('migrations/2026_10_24_100100_asigna_codigo_interno_a_revendedores.php');
        $migracion->up();

        $codigo = DB::table('revendedor_distribuidora')->where('id', $afiliacion->id)->value('codigo_interno');
        $this->assertMatchesRegularExpression('/^REV-\d{3,}$/', $codigo);
        $this->assertSame(0, DB::table('revendedor_distribuidora')->where('distribuidora_id', $id)->whereNull('codigo_interno')->count());
        $this->assertSame(
            DB::table('revendedor_distribuidora')->where('distribuidora_id', $id)->count(),
            DB::table('revendedor_distribuidora')->where('distribuidora_id', $id)->distinct()->count('codigo_interno'),
        );
    }

    // ---------------------------------------------------------------- Modales y fotos

    public function test_invitar_empleado_con_foto_en_el_modal(): void
    {
        Livewire::test('distribuidora.usuarios', ['seccion' => 'empleados'])
            ->call('abrirFormularioInvitarEmpleado')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->set('empleado_nombre', 'Laura Foto')
            ->set('empleado_email', 'laura.foto@gmail.com')
            ->set('empleado_password', 'ClaveSegura123')
            ->set('empleado_password_confirmation', 'ClaveSegura123')
            ->set('empleado_foto', UploadedFile::fake()->image('laura.jpg', 300, 300))
            ->call('guardarEmpleado')
            ->assertHasNoErrors()
            ->assertSet('mostrandoFormularioEmpleado', false)
            ->assertSeeHtml('alt="Foto de Laura Foto"');

        $url = Usuario::where('email', 'laura.foto@gmail.com')->value('foto_url');
        $this->assertNotNull($url);
        $this->assertCount(1, Storage::disk('s3')->files('perfiles/empleados'));
    }

    public function test_editar_empleado_cambia_nombre_telefono_y_foto_pero_no_el_correo(): void
    {
        $staff = DistribuidoraStaff::withoutGlobalScopes()->with('usuario')->where('tipo', 'empleado')
            ->where('distribuidora_id', $this->distribuidoraId())->firstOrFail();
        $correo = $staff->usuario->email;

        Livewire::test('distribuidora.usuarios', ['seccion' => 'empleados'])
            ->call('abrirFormularioEditarEmpleado', $staff->id)
            ->assertSet('empleado_email_actual', $correo)
            ->set('empleado_nombre', 'Nombre Editado')
            ->set('empleado_telefono', '9611112222')
            ->set('empleado_email', 'hackeo@gmail.com')
            ->set('empleado_foto', UploadedFile::fake()->image('nueva.png'))
            ->call('guardarEmpleado')
            ->assertHasNoErrors();

        $usuario = $staff->usuario->fresh();
        $this->assertSame('Nombre Editado', $usuario->nombre);
        $this->assertSame('9611112222', $usuario->telefono);
        $this->assertSame($correo, $usuario->email);
        $this->assertNotNull($usuario->foto_url);
        $this->assertTrue(Auditoria::where('accion', 'empleado.actualizado')->exists());
    }

    public function test_la_foto_debe_ser_imagen_y_pesar_poco(): void
    {
        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Foto Mala')
            ->set('revendedor_foto', UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'))
            ->call('guardarRevendedor')
            ->assertHasErrors(['revendedor_foto']);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Foto Grande')
            ->set('revendedor_foto', UploadedFile::fake()->image('grande.jpg')->size(3000))
            ->call('guardarRevendedor')
            ->assertHasErrors(['revendedor_foto']);
    }

    public function test_el_revendedor_con_foto_sale_con_avatar_en_la_lista(): void
    {
        $afiliacion = $this->afiliar('Avatar Revendedor', [
            'revendedor_foto' => UploadedFile::fake()->image('a.webp'),
        ]);

        $this->assertNotNull($afiliacion->revendedor()->withoutGlobalScopes()->value('foto_url'));

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSeeHtml('alt="Foto de Avatar Revendedor"')
            ->assertSee($afiliacion->codigo_interno);
    }

    public function test_sin_foto_se_ven_las_iniciales(): void
    {
        $this->afiliar('Zoe Quintana');

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSeeHtml('<span aria-hidden="true">ZQ</span>');
    }

    // ---------------------------------------------------------------- Eliminar definitivamente

    public function test_eliminar_un_revendedor_sin_movimientos(): void
    {
        $afiliacion = $this->afiliar('Para Borrar', [
            'revendedor_email' => 'para.borrar@gmail.com',
            'revendedor_foto' => UploadedFile::fake()->image('b.jpg'),
        ]);
        $revendedorId = $afiliacion->revendedor_id;
        $this->assertNotNull(Usuario::where('email', 'para.borrar@gmail.com')->first());

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('pedirEliminar', 'revendedor', $afiliacion->id)
            ->assertSee('Eliminar definitivamente')
            ->assertSee('Para Borrar')
            ->call('eliminarDefinitivamente')
            ->assertSet('porEliminar', null)
            ->assertDispatched('guardado');

        $this->assertNull(RevendedorDistribuidora::withoutGlobalScopes()->find($afiliacion->id));
        $this->assertNull(Revendedor::withoutGlobalScopes()->find($revendedorId));
        $this->assertNull(Usuario::where('email', 'para.borrar@gmail.com')->first());
        $this->assertSame([], Storage::disk('s3')->allFiles('perfiles/revendedores'));
        $this->assertTrue(Auditoria::where('accion', 'revendedor.eliminado_definitivamente')
            ->where('entidad_id', $afiliacion->id)->exists());
    }

    public function test_no_elimina_a_un_revendedor_con_pedidos(): void
    {
        $afiliacionId = $this->afiliar('Con Pedido')->id;
        $this->crearPedido([
            'tipo' => 'revendedor',
            'revendedor_distribuidora_id' => $afiliacionId,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $this->admin->id)->value('id'),
        ]);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('pedirEliminar', 'revendedor', $afiliacionId)
            ->call('eliminarDefinitivamente')
            ->assertNotSet('errorEliminar', null)
            ->assertSee('Dale de baja')
            ->assertNotSet('porEliminar', null);

        $this->assertNotNull(RevendedorDistribuidora::withoutGlobalScopes()->find($afiliacionId));
        $this->assertFalse(Auditoria::where('accion', 'revendedor.eliminado_definitivamente')->exists());
    }

    public function test_eliminar_un_empleado_sin_movimientos(): void
    {
        Livewire::test('distribuidora.usuarios', ['seccion' => 'empleados'])
            ->call('abrirFormularioInvitarEmpleado')
            ->set('empleado_nombre', 'Temporal Borrable')
            ->set('empleado_email', 'temporal.borrable@gmail.com')
            ->set('empleado_password', 'ClaveSegura123')
            ->set('empleado_password_confirmation', 'ClaveSegura123')
            ->call('guardarEmpleado')
            ->assertHasNoErrors();

        $usuario = Usuario::where('email', 'temporal.borrable@gmail.com')->firstOrFail();
        $staff = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->firstOrFail();

        Livewire::test('distribuidora.usuarios', ['seccion' => 'empleados'])
            ->call('pedirEliminar', 'empleado', $staff->id)
            ->call('eliminarDefinitivamente')
            ->assertSet('errorEliminar', null);

        $this->assertNull(DistribuidoraStaff::withoutGlobalScopes()->find($staff->id));
        $this->assertNull(Usuario::find($usuario->id));
        $this->assertTrue(Auditoria::where('accion', 'empleado.eliminado_definitivamente')->where('entidad_id', $staff->id)->exists());
    }

    public function test_no_elimina_a_un_empleado_con_pedidos_capturados(): void
    {
        $staffId = (int) DistribuidoraStaff::withoutGlobalScopes()->where('tipo', 'empleado')
            ->where('distribuidora_id', $this->distribuidoraId())->value('id');
        $this->crearPedido([
            'tipo' => 'revendedor',
            'revendedor_distribuidora_id' => RevendedorDistribuidora::withoutGlobalScopes()->where('distribuidora_id', $this->distribuidoraId())->value('id'),
            'capturado_por_staff_id' => $staffId,
        ]);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'empleados'])
            ->call('pedirEliminar', 'empleado', $staffId)
            ->call('eliminarDefinitivamente')
            ->assertNotSet('errorEliminar', null)
            ->assertSee('Dale de baja');

        $this->assertNotNull(DistribuidoraStaff::withoutGlobalScopes()->find($staffId));
        $this->assertFalse(Auditoria::where('accion', 'empleado.eliminado_definitivamente')->exists());
    }

    public function test_cancelar_no_borra_nada(): void
    {
        $afiliacion = $this->afiliar('No Me Borres');

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('pedirEliminar', 'revendedor', $afiliacion->id)
            ->call('cancelarEliminar')
            ->assertSet('porEliminar', null);

        $this->assertNotNull($afiliacion->fresh());
    }
}
