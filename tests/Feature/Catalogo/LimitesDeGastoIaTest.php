<?php

namespace Tests\Feature\Catalogo;

use App\Jobs\ProcesarCatalogoConIa;
use App\Models\ImportacionCatalogo;
use App\Models\Usuario;
use App\Services\Ia\ExtraerProductosDelCatalogoAction;
use App\Services\Ia\LectorDeCatalogos;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Apoyo\LectorDeCatalogosFalso;
use Tests\TestCase;

/** Topes de gasto de la lectura de catálogos con IA (config/ia.php). */
class LimitesDeGastoIaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const DISCO = 'catalogos_de_prueba';

    private LectorDeCatalogosFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Storage::fake(self::DISCO);
        config([
            'filesystems.catalogos_disk'    => self::DISCO,
            'ia.paginas_por_bloque'         => 10,
            'ia.max_paginas'                => 150,
            'ia.max_importaciones_por_dia'  => 2,
            'ia.max_usd_por_mes'            => 50,
        ]);

        $this->lector = new LectorDeCatalogosFalso([]);
        $this->app->instance(LectorDeCatalogos::class, $this->lector);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function pdfDe(int $paginas): string
    {
        $pdf = "%PDF-1.6\n1 0 obj\n<< /Type /Pages /Kids [] /Count {$paginas} >>\nendobj\n";

        for ($i = 0; $i < $paginas; $i++) {
            $pdf .= ($i + 2)." 0 obj\n<< /Type /Page /Parent 1 0 R >>\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function importacion(int $paginas = 20, array $cambios = []): ImportacionCatalogo
    {
        $ruta = 'catalogos-ia/2026/10/prueba-'.uniqid().'.pdf';
        Storage::disk(self::DISCO)->put($ruta, $this->pdfDe($paginas));

        return ImportacionCatalogo::create(array_merge([
            'linea_id'                => 1,
            'archivo_url'             => $ruta,
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'cargado',
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ], $cambios));
    }

    public function test_un_catalogo_con_demasiadas_paginas_no_se_manda_a_la_ia(): void
    {
        $importacion = $this->importacion(151);

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($importacion);

        $importacion->refresh();
        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('151 páginas', $importacion->mensaje_error);
        $this->assertStringContainsString('máximo para leer con la IA es 150', $importacion->mensaje_error);
        $this->assertNull($importacion->archivo_ia_id);
        $this->assertNull($importacion->costo_usd);
    }

    public function test_al_llegar_al_tope_del_mes_no_deja_procesar_y_lo_explica(): void
    {
        Queue::fake();
        $this->importacion(10, ['estado' => 'requiere_revision', 'costo_usd' => 50.0, 'procesamiento_iniciado_at' => now()]);
        $nueva = $this->importacion();

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $nueva->id)
            ->assertSet('esError', true)
            ->assertSee('permitidos este mes');

        Queue::assertNothingPushed();
        $this->assertSame('cargado', $nueva->fresh()->estado);
    }

    public function test_el_worker_tambien_respeta_el_tope_del_mes(): void
    {
        $this->importacion(10, ['estado' => 'requiere_revision', 'costo_usd' => 60.0, 'procesamiento_iniciado_at' => now()]);
        $nueva = $this->importacion();

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($nueva);

        $this->assertSame('error', $nueva->fresh()->estado);
        $this->assertStringContainsString('este mes', $nueva->fresh()->mensaje_error);
    }

    public function test_lo_gastado_el_mes_pasado_no_cuenta(): void
    {
        Queue::fake();
        $this->importacion(10, ['estado' => 'requiere_revision', 'costo_usd' => 80.0, 'procesamiento_iniciado_at' => now()->subMonthNoOverflow()->startOfMonth()]);
        $nueva = $this->importacion();

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $nueva->id)
            ->assertSet('esError', false);

        Queue::assertPushed(ProcesarCatalogoConIa::class);
        $this->assertNotNull($nueva->fresh()->procesamiento_iniciado_at);
    }

    public function test_tope_de_importaciones_por_dia_por_persona(): void
    {
        Queue::fake();
        $this->actingAs($this->adminGeneral());

        foreach ([1, 2] as $vez) {
            Livewire::test('admin.catalogos-importaciones')
                ->call('procesar', $this->importacion()->id)
                ->assertSet('esError', false);
        }

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $this->importacion()->id)
            ->assertSet('esError', true)
            ->assertSee('máximo por día');

        Queue::assertPushed(ProcesarCatalogoConIa::class, 2);
    }

    public function test_en_cero_los_topes_no_aplican(): void
    {
        Queue::fake();
        config(['ia.max_importaciones_por_dia' => 0, 'ia.max_usd_por_mes' => 0]);
        $this->importacion(10, ['estado' => 'requiere_revision', 'costo_usd' => 999.0, 'procesamiento_iniciado_at' => now()]);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $this->importacion()->id)
            ->assertSet('esError', false);

        Queue::assertPushed(ProcesarCatalogoConIa::class, 1);
    }
}
