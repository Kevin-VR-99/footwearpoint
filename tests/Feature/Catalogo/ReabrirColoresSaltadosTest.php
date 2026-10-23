<?php

namespace Tests\Feature\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-282 — Rescatar las filas que el aprobador viejo dejó en tierra de nadie.
 *
 * Once productos del Cklass se saltaron por ser el mismo modelo en otro
 * color. El código viejo los marcó "aprobado" sin ligarlos a ninguna
 * publicación, así que ya no los alcanza nadie: aprobar solo toma las filas
 * en "corregido" y catalogo:publicar-fotos-ia pide
 * producto_campana_creado_id.
 *
 * El arreglo del #255 evita que vuelva a pasar; esto rescata las que ya
 * quedaron así.
 */
class ReabrirColoresSaltadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function importacion(): ImportacionCatalogo
    {
        return ImportacionCatalogo::create([
            'linea_id'                => 1,
            'archivo_url'             => 'catalogos-ia/reabrir.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'aprobado',
            'paginas'                 => 2,
            'iniciada_por_usuario_id' => Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);
    }

    private function fila(ImportacionCatalogo $importacion, string $estado, ?int $productoId, ?int $publicacionId): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'             => $importacion->id,
            'datos_extraidos'            => ['pagina' => 32, 'codigo_catalogo' => 'DUO-613', 'color' => 'BEIGE'],
            'campos_dudosos'             => [],
            'estado'                     => $estado,
            'producto_creado_id'         => $productoId,
            'producto_campana_creado_id' => $publicacionId,
        ]);
    }

    public function test_reabre_solo_las_que_quedaron_aprobadas_sin_publicacion(): void
    {
        $importacion = $this->importacion();
        $publicacion = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $productoId = $publicacion->producto_id;

        // La que hay que rescatar: aprobada, con producto, sin publicación.
        $saltada = $this->fila($importacion, 'aprobado', $productoId, null);

        // Las que NO se deben tocar.
        $aprobadaDeVerdad = $this->fila($importacion, 'aprobado', $productoId, $publicacion->id);
        $pendiente = $this->fila($importacion, 'pendiente', null, null);
        $descartada = $this->fila($importacion, 'descartado', null, null);

        $this->artisan('catalogo:reabrir-colores-saltados --confirmar')
            ->expectsOutputToContain('Filas reabiertas: 1.')
            ->assertSuccessful();

        $this->assertSame('corregido', $saltada->refresh()->estado);
        $this->assertSame('aprobado', $aprobadaDeVerdad->refresh()->estado);
        $this->assertSame('pendiente', $pendiente->refresh()->estado);
        $this->assertSame('descartado', $descartada->refresh()->estado);
    }

    /** Sin --confirmar no toca nada: se ve qué pasaría y se decide. */
    public function test_sin_confirmar_solo_cuenta(): void
    {
        $importacion = $this->importacion();
        $publicacion = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $saltada = $this->fila($importacion, 'aprobado', $publicacion->producto_id, null);

        $this->artisan('catalogo:reabrir-colores-saltados')
            ->expectsOutputToContain('Filas que se reabrirían: 1.')
            ->assertSuccessful();

        $this->assertSame('aprobado', $saltada->refresh()->estado);
    }

    public function test_si_no_hay_nada_que_reabrir_lo_dice(): void
    {
        $this->artisan('catalogo:reabrir-colores-saltados --confirmar')
            ->expectsOutputToContain('No hay filas que reabrir.')
            ->assertSuccessful();
    }

    /** Correrlo dos veces no hace daño: la segunda ya no encuentra nada. */
    public function test_se_puede_correr_otra_vez(): void
    {
        $importacion = $this->importacion();
        $publicacion = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->fila($importacion, 'aprobado', $publicacion->producto_id, null);

        $this->artisan('catalogo:reabrir-colores-saltados --confirmar')->assertSuccessful();

        $this->artisan('catalogo:reabrir-colores-saltados --confirmar')
            ->expectsOutputToContain('No hay filas que reabrir.')
            ->assertSuccessful();
    }
}
