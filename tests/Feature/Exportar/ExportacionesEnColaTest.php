<?php

namespace Tests\Feature\Exportar;

use App\Jobs\GenerarExportacion;
use App\Models\Exportacion;
use App\Models\Notificacion;
use App\Models\Usuario;
use App\Services\Exportar\ExportacionesEnCola;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Exportaciones grandes en cola: umbral, job, aviso y descarga firmada solo para quien la pidió. */
class ExportacionesEnColaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';
    private const OTRA_ADMIN = 'admin@boutiquedelcalzado.test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['exportaciones.disco' => 'local']);
    }

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

    /** Un pedido de cliente directo en la distribuidora de la sesión. */
    private function pedidoDe(string $folio): void
    {
        \App\Models\Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => \App\Models\Sucursal::query()->value('id'),
            'estado' => 'colocado', 'subtotal' => 10, 'total' => 10, 'fecha_colocacion' => now(),
            'capturado_por_staff_id' => (int) \App\Models\DistribuidoraStaff::query()->value('id'),
            'folio' => $folio, 'tipo' => 'cliente_directo', 'cliente_directo_id' => \App\Models\ClienteDirecto::query()->value('id'),
        ]);
    }

    /** Pide el reporte PDF con umbral 0 (todo va a la cola) y corre el job. */
    private function exportacionLista(string $email = self::ADMIN, string $formato = 'pdf'): Exportacion
    {
        $this->como($email);
        config(['exportaciones.umbral_reporte' => -1]);

        $this->get(route('reportes.descargar', ['formato' => $formato]))
            ->assertRedirect(route('reportes.index'))
            ->assertSessionHas('exportacion_en_cola');

        return Exportacion::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_un_reporte_chico_se_descarga_al_momento(): void
    {
        Bus::fake();
        $this->como(self::ADMIN);

        $this->get(route('reportes.descargar', ['formato' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Bus::assertNotDispatched(GenerarExportacion::class);
        $this->assertSame(0, Exportacion::withoutGlobalScopes()->count());
    }

    public function test_un_reporte_grande_va_a_la_cola_con_tenant_y_usuario(): void
    {
        Bus::fake();
        $admin = $this->como(self::ADMIN);
        config(['exportaciones.umbral_reporte' => -1]);

        $this->get(route('reportes.descargar', ['formato' => 'xlsx', 'desde' => '2026-01-01']))
            ->assertRedirect(route('reportes.index'));

        $e = Exportacion::withoutGlobalScopes()->sole();
        $this->assertSame($admin->id, $e->usuario_id);
        $this->assertSame(Tenant::id(), $e->distribuidora_id);
        $this->assertSame(['xlsx', 'pendiente', '2026-01-01'], [$e->formato, $e->estado, $e->filtros['desde']]);
        Bus::assertDispatched(GenerarExportacion::class, fn ($job) => $job->exportacionId === $e->id);

        $this->get(route('reportes.index'))->assertOk()->assertSee('lo estamos preparando');
    }

    public function test_el_job_guarda_el_archivo_privado_y_avisa_con_enlace(): void
    {
        $e = $this->exportacionLista()->refresh(); // QUEUE_CONNECTION=sync: ya corrió

        $this->assertSame(Exportacion::LISTA, $e->estado);
        $this->assertStringStartsWith('exportaciones/'.$e->distribuidora_id.'/', $e->ruta);
        Storage::disk('local')->assertExists($e->ruta);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($e->ruta));
        $this->assertTrue($e->expira_at->isFuture());

        $aviso = Notificacion::withoutGlobalScopes()->where('entidad_tipo', 'exportacion')->where('entidad_id', $e->id)->sole();
        $this->assertSame([$e->usuario_id, $e->distribuidora_id, 'exportacion_lista'], [$aviso->usuario_id, $aviso->distribuidora_id, $aviso->tipo]);

        $this->get(route('notificaciones.index'))->assertOk()
            ->assertSee('data-descargar-exportacion', false)
            ->assertSee('signature=', false);
    }

    public function test_el_enlace_firmado_descarga_el_archivo(): void
    {
        $e = $this->exportacionLista()->refresh();

        $r = $this->get(ExportacionesEnCola::enlace($e));
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $r->headers->get('Content-Disposition'));
    }

    public function test_sin_firma_o_con_firma_vencida_no_descarga(): void
    {
        $e = $this->exportacionLista()->refresh();

        $this->get(route('exportaciones.descargar', ['exportacion' => $e->id]))->assertForbidden();

        $enlace = ExportacionesEnCola::enlace($e);
        $this->travel(config('exportaciones.minutos_enlace') + 1)->minutes();
        $this->get($enlace)->assertForbidden();
    }

    public function test_otro_usuario_de_la_misma_distribuidora_no_puede_descargarla(): void
    {
        $e = $this->exportacionLista()->refresh();
        $enlace = ExportacionesEnCola::enlace($e);

        $this->como(self::EMPLEADO);
        $this->get($enlace)->assertNotFound();
    }

    public function test_otra_distribuidora_no_puede_descargarla(): void
    {
        $e = $this->exportacionLista()->refresh();
        $enlace = ExportacionesEnCola::enlace($e);

        $this->como(self::OTRA_ADMIN);
        $this->get($enlace)->assertNotFound();
    }

    public function test_sin_sesion_manda_al_login(): void
    {
        $e = $this->exportacionLista()->refresh();
        $enlace = ExportacionesEnCola::enlace($e);

        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get($enlace)->assertRedirect();
        $this->assertGuest();
    }

    public function test_un_archivo_vencido_ya_no_se_descarga_y_el_comando_lo_borra(): void
    {
        $e = $this->exportacionLista()->refresh();
        $e->update(['expira_at' => now()->subMinute()]);

        $this->get(ExportacionesEnCola::enlace($e))->assertStatus(410);

        $this->artisan('exportaciones:limpiar')->assertSuccessful();
        Storage::disk('local')->assertMissing($e->ruta);
        $this->assertNull($e->refresh()->ruta);
    }

    public function test_el_job_usa_la_distribuidora_de_la_exportacion_aunque_otra_tenga_sesion(): void
    {
        Bus::fake();
        $this->como(self::ADMIN);
        config(['exportaciones.umbral_listado' => -1]);
        $idA = Tenant::id();
        $this->pedidoDe('COLA-A');
        $this->como(self::OTRA_ADMIN);
        $this->pedidoDe('COLA-B');
        $this->como(self::ADMIN);

        Livewire::test('pedidos.index')->call('exportar')->assertSet('avisoExportacion', ExportacionesEnCola::MENSAJE_EN_COLA)->assertNoFileDownloaded();
        $e = Exportacion::withoutGlobalScopes()->sole();
        $this->assertSame('pedidos', $e->tipo);

        // El worker podría tener otro contexto: el job debe forzar el de la exportación.
        $this->como(self::OTRA_ADMIN);
        (new GenerarExportacion($e->id))->handle();

        $e->refresh();
        $this->assertSame(Exportacion::LISTA, $e->estado);
        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('local')->path($e->ruta));
        $folios = collect($libro->getActiveSheet()->toArray())->pluck(0)->filter()->all();
        $foliosA = \App\Models\Pedido::withoutGlobalScopes()->where('distribuidora_id', $idA)->pluck('folio')->all();
        $foliosOtros = \App\Models\Pedido::withoutGlobalScopes()->where('distribuidora_id', '!=', $idA)->pluck('folio')->all();
        $this->assertContains('COLA-A', $folios);
        $this->assertNotContains('COLA-B', $folios);
        $this->assertNotEmpty(array_intersect($folios, $foliosA));
        $this->assertSame([], array_values(array_intersect($folios, $foliosOtros)));
    }

    public function test_un_listado_chico_de_pedidos_sigue_siendo_inmediato(): void
    {
        Bus::fake();
        $this->como(self::EMPLEADO);

        Livewire::test('pedidos.index')->call('exportar')->assertFileDownloaded()->assertSet('avisoExportacion', '');
        Bus::assertNotDispatched(GenerarExportacion::class);
    }

    public function test_si_el_job_falla_queda_fallida_y_avisa(): void
    {
        $this->como(self::ADMIN);
        $e = Exportacion::create([
            'distribuidora_id' => Tenant::id(), 'usuario_id' => auth()->id(),
            'tipo' => 'reporte', 'formato' => 'pdf', 'filtros' => [], 'estado' => 'pendiente',
        ]);

        (new GenerarExportacion($e->id))->failed(new \RuntimeException('falla de prueba'));

        $this->assertSame(Exportacion::FALLIDA, $e->refresh()->estado);
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('tipo', 'exportacion_fallida')->where('entidad_id', $e->id)->exists());
    }
}
