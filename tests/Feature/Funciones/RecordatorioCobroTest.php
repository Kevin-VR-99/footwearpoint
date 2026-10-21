<?php

namespace Tests\Feature\Funciones;

use App\Mail\RecordatorioCobroMail;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Cobranza\RecordatorioCobroService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Recordatorio de cobro desde «Por cobrar»: cola, límite de 24 h, auditoría, permisos y aislamiento. Nunca manda correos reales. */
class RecordatorioCobroTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

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

    private function deudor(?string $email = 'ana@cliente.test'): ClienteDirecto
    {
        $cliente = ClienteDirecto::query()->orderBy('id')->firstOrFail();
        $cliente->forceFill(['email' => $email])->save();
        $cliente->usuario?->forceFill(['email' => $email ?? 'sin-correo-'.uniqid().'@x.invalid']);

        Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'RC-1', 'tipo' => 'cliente_directo', 'cliente_directo_id' => $cliente->id,
            'estado' => 'colocado', 'subtotal' => 1500, 'total' => 1500, 'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);

        return $cliente;
    }

    public function test_el_admin_ve_la_vista_previa_y_envia_en_cola_con_auditoria(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor();
        $clave = 'cliente_directo:'.$cliente->id;

        Livewire::test('reportes.index')
            ->assertSee('data-recordatorio="'.$clave.'"', false)
            ->call('previsualizarRecordatorio', $clave)
            ->assertSee('Recordatorio de cobro')
            ->assertSee('RC-1')
            ->assertSee('$1,500.00')
            ->call('enviarRecordatorios')
            ->assertDispatched('guardado');

        Mail::assertQueued(RecordatorioCobroMail::class, fn ($m) => $m->hasTo('ana@cliente.test')
            && $m->datos['saldo'] === 1500.0
            && $m->datos['pedidos'][0]['folio'] === 'RC-1'
            && $m->datos['distribuidora']['nombre'] !== '');
        Mail::assertNothingSent();
        $this->assertDatabaseHas('auditorias', ['accion' => RecordatorioCobroService::ACCION, 'entidad_tipo' => 'cliente_directo', 'entidad_id' => $cliente->id]);
    }

    public function test_el_correo_lleva_saldo_detalle_y_contacto(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor();
        $datos = app(RecordatorioCobroService::class)->datos('cliente_directo', $cliente->id);

        $html = (new RecordatorioCobroMail($datos))->render();

        $this->assertStringContainsString('$1,500.00', $html);
        $this->assertStringContainsString('RC-1', $html);
        $this->assertStringContainsString($datos['distribuidora']['nombre'], $html);
    }

    public function test_solo_uno_cada_24_horas_por_deudor(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor();
        $servicio = app(RecordatorioCobroService::class);

        $servicio->enviar('cliente_directo', $cliente->id);
        $this->assertFalse($servicio->datos('cliente_directo', $cliente->id)['disponible']);

        try {
            $servicio->enviar('cliente_directo', $cliente->id);
            $this->fail('Debió rechazar el segundo recordatorio.');
        } catch (\App\Exceptions\OperacionInvalidaException) {
        }
        Mail::assertQueued(RecordatorioCobroMail::class, 1);

        $this->travel(25)->hours();
        $this->assertTrue($servicio->datos('cliente_directo', $cliente->id)['disponible']);
    }

    public function test_sin_correo_el_boton_se_desactiva_con_explicacion(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor(null);
        $cliente->forceFill(['usuario_id' => null])->save();

        Livewire::test('reportes.index')->assertSee('No tiene correo registrado.');
        $this->assertFalse(app(RecordatorioCobroService::class)->datos('cliente_directo', $cliente->id)['disponible']);
    }

    public function test_envio_masivo_a_los_seleccionados(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor();

        Livewire::test('reportes.index')
            ->set('seleccionDeudores', ['cliente_directo:'.$cliente->id])
            ->call('previsualizarSeleccion')
            ->assertSee('1 de 1 destinatario(s)')
            ->call('enviarRecordatorios')
            ->assertSet('seleccionDeudores', []);

        Mail::assertQueued(RecordatorioCobroMail::class, 1);
    }

    public function test_el_empleado_sin_permiso_no_puede_y_con_permiso_si(): void
    {
        $this->como('admin@calzadosramirez.test');
        $cliente = $this->deudor();

        $this->como('empleado@calzadosramirez.test');
        $this->get(route('reportes.index'))->assertForbidden();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail()->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();
        $this->como('empleado@calzadosramirez.test');

        Livewire::test('reportes.index')
            ->call('previsualizarRecordatorio', 'cliente_directo:'.$cliente->id)
            ->call('enviarRecordatorios');
        Mail::assertQueued(RecordatorioCobroMail::class, 1);
    }

    public function test_no_alcanza_deudores_de_otra_distribuidora(): void
    {
        $otra = Distribuidora::query()->where('slug', 'boutique-del-calzado')->firstOrFail();
        $ajeno = ClienteDirecto::withoutGlobalScopes()->create([
            'distribuidora_id' => $otra->id, 'nombre' => 'Ajeno', 'email' => 'ajeno@otra.test', 'estado' => 'activo',
        ]);
        $this->como('admin@calzadosramirez.test');

        Livewire::test('reportes.index')
            ->call('previsualizarRecordatorio', 'cliente_directo:'.$ajeno->id)
            ->assertDontSee('ajeno@otra.test')
            ->call('enviarRecordatorios');

        Mail::assertNothingQueued();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(RecordatorioCobroService::class)->enviar('cliente_directo', $ajeno->id);
    }
}
