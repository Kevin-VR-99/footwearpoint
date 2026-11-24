<?php

namespace Tests\Feature\Catalogo;

use App\Jobs\AprobarCatalogoImportado;
use App\Models\Auditoria;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-377 — Aprobar un catálogo se fue a la cola.
 *
 * EL PROBLEMA. El 24-nov-2026, en producción, aprobar 30 productos murió con
 * «Maximum execution time of 30 seconds exceeded» dentro de Guzzle: cada
 * producto hace cuatro viajes al bucket (recorte, original, miniatura,
 * mediana), unos 2.3 s, y el servidor web corta a los 30. El usuario vio un
 * «Error 500» tres veces seguidas aunque el catálogo iba entrando bien.
 *
 * LA SOLUCIÓN. El trabajo lo hace el worker (`queue:work --timeout=1800`),
 * igual que la lectura con IA de TG-238, y la pantalla enseña el avance.
 *
 * LO QUE ESTAS PRUEBAS CUIDAN. Mandar algo a la cola no es gratis: se pierde
 * la sesión, se puede encolar dos veces, y si el worker se cae el catálogo se
 * queda colgado. Cada una de esas tres cosas tiene su prueba aquí.
 */
class AprobarCatalogoEnColaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    private int $campanaId;

    private int $categoriaId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $campana = Campana::firstOrFail();
        $this->campanaId = $campana->id;
        $this->categoriaId = CategoriaProducto::firstOrFail()->id;
        $this->marcaId = Marca::firstOrFail()->id;

        $this->importacion = ImportacionCatalogo::create([
            'linea_id'                => $campana->linea_id,
            'campana_id'              => $campana->id,
            'archivo_url'             => 'catalogos-ia/2026/11/dankriz.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 9,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    /** @return array<int, ProductoImportadoStaging> */
    private function revisados(int $cuantos = 3): array
    {
        $negro = Color::whereRaw('LOWER(nombre) = ?', ['negro'])->firstOrFail()->id;
        $filas = [];

        foreach (range(1, $cuantos) as $i) {
            $filas[] = ProductoImportadoStaging::create([
                'importacion_id'  => $this->importacion->id,
                'datos_extraidos' => [
                    'pagina'          => $i,
                    'marca'           => null,
                    'modelo'          => 'DANKRIZ-'.$i,
                    'nombre'          => null,
                    'color'           => 'MIEL',
                    'color_base_id'   => $negro,
                    'codigo_catalogo' => '033-'.$i,
                    'precio_publico'  => 699.0,
                    'talla_desde'     => '22',
                    'talla_hasta'     => '24',
                    'medios_numeros'  => false,
                    'disponibilidad'  => null,
                ],
                'campos_dudosos' => [],
                'estado'         => 'corregido',
            ]);
        }

        return $filas;
    }

    private function pantalla()
    {
        $this->actingAs($this->adminGeneral());

        return Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId);
    }

    // ------------------------------------------------------------------
    // Que de verdad se vaya a la cola
    // ------------------------------------------------------------------

    public function test_aprobar_encola_el_trabajo_en_vez_de_hacerlo_en_la_peticion(): void
    {
        Queue::fake();
        $this->revisados();

        $this->pantalla()->call('aprobar')->assertHasNoErrors();

        Queue::assertPushed(
            AprobarCatalogoImportado::class,
            fn (AprobarCatalogoImportado $job) => $job->importacionId === $this->importacion->id
                && $job->campanaId === $this->campanaId
                && $job->categoriaPorOmision === $this->categoriaId
                && $job->marcaPorOmision === $this->marcaId
                && $job->usuarioId === $this->adminGeneral()->id
        );

        // Y la petición NO aprobó nada por su cuenta.
        $this->assertSame(
            3,
            $this->importacion->productosStaging()->where('estado', 'corregido')->count()
        );
    }

    /**
     * El estado se pone ANTES de encolar. Si se pusiera después (o lo pusiera
     * el worker), el usuario vería el botón intacto hasta que la cola tomara
     * el trabajo y pensaría que no pasó nada.
     */
    public function test_el_catalogo_queda_en_aprobando_aunque_el_worker_tarde(): void
    {
        Queue::fake();
        $this->revisados();

        $this->pantalla()->call('aprobar');

        $this->assertSame('aprobando', $this->importacion->fresh()->estado);
        $this->assertTrue($this->importacion->fresh()->estaAprobando());
    }

    public function test_no_se_encola_dos_veces_el_mismo_catalogo(): void
    {
        Queue::fake();
        $this->revisados();

        $pantalla = $this->pantalla();
        $pantalla->call('aprobar');
        $pantalla->call('aprobar')->assertSet('esError', false);

        Queue::assertPushed(AprobarCatalogoImportado::class, 1);
    }

    // ------------------------------------------------------------------
    // Lo que la cola se lleva por delante si nadie lo cuida
    // ------------------------------------------------------------------

    /**
     * ESTA ES LA IMPORTANTE. En la cola no hay sesión, y TODA la auditoría del
     * proyecto sale de Auth::id() (RegistrarAuditoriaAction). Sin
     * Auth::onceUsingId en el job, los productos del catálogo quedarían
     * registrados como creados por nadie — y la auditoría es justo lo que
     * hace defendible la importación con IA.
     */
    public function test_la_auditoria_sigue_sabiendo_quien_aprobo(): void
    {
        $this->revisados(1);
        $admin = $this->adminGeneral();

        $this->importacion->update(['estado' => 'aprobando']);

        // Como en el worker de verdad: nadie ha iniciado sesión.
        Auth::logout();
        $this->assertNull(Auth::id());

        AprobarCatalogoImportado::dispatchSync(
            $this->importacion->id,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $admin->id,
        );

        $delLote = Auditoria::where('accion', 'importacion_catalogo.aprobada')
            ->where('entidad_id', $this->importacion->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $delLote->usuario_id);

        // Y también el alta de cada producto, que es la que de verdad se
        // consulta cuando alguien pregunta "¿quién metió esto al catálogo?".
        $delProducto = Auditoria::where('accion', 'catalogo.producto.creado')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $delProducto->usuario_id);
    }

    public function test_el_worker_guarda_el_resumen_donde_la_pantalla_lo_encuentra(): void
    {
        $this->revisados(2);
        $this->importacion->update(['estado' => 'aprobando']);

        AprobarCatalogoImportado::dispatchSync(
            $this->importacion->id,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $this->adminGeneral()->id,
        );

        $fila = $this->importacion->fresh();

        $this->assertIsArray($fila->resumen_aprobacion);
        $this->assertSame(2, $fila->resumen_aprobacion['aprobados']);
        $this->assertSame('aprobado', $fila->estado);
    }

    /**
     * Un trabajo repetido (un reintento viejo, dos pestañas) no vuelve a
     * aprobar: si el catálogo ya no está «aprobando», el worker se va.
     */
    public function test_el_trabajo_no_hace_nada_si_el_catalogo_ya_no_esta_aprobando(): void
    {
        $this->revisados(2);
        $this->importacion->update(['estado' => 'requiere_revision']);

        AprobarCatalogoImportado::dispatchSync(
            $this->importacion->id,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $this->adminGeneral()->id,
        );

        $this->assertSame(
            2,
            $this->importacion->productosStaging()->where('estado', 'corregido')->count(),
            'Sin el estado «aprobando» el worker no debe tocar nada.'
        );
    }

    /**
     * Si el worker se cae o lo reinicia un deploy, el catálogo NO puede
     * quedarse en «aprobando» para siempre: ahí el botón no existe y no habría
     * forma de terminar el trabajo desde la pantalla.
     */
    public function test_si_el_worker_se_cae_el_catalogo_no_queda_colgado(): void
    {
        $this->importacion->update(['estado' => 'aprobando']);

        (new AprobarCatalogoImportado(
            $this->importacion->id,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $this->adminGeneral()->id,
        ))->failed(new RuntimeException('el worker se murió'));

        $fila = $this->importacion->fresh();

        // requiere_revision y NO error: lo que entró está bien guardado.
        $this->assertSame('requiere_revision', $fila->estado);
        $this->assertStringContainsString('está guardado', $fila->mensaje_error);
        $this->assertStringContainsString('vuelve a presionar', mb_strtolower($fila->mensaje_error));
    }

    public function test_no_se_puede_quitar_un_catalogo_que_se_esta_aprobando(): void
    {
        $this->revisados(1);
        $this->importacion->update(['estado' => 'aprobando']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('se está aprobando');

        app(SubirCatalogoParaImportarAction::class)->eliminar($this->importacion->fresh());
    }

    // ------------------------------------------------------------------
    // El avance en pantalla
    // ------------------------------------------------------------------

    public function test_mientras_aprueba_la_pantalla_ensena_el_avance_y_se_refresca_sola(): void
    {
        $filas = $this->revisados(4);
        $filas[0]->update(['estado' => 'aprobado']);
        $this->importacion->update(['estado' => 'aprobando']);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->assertSee('Van 1 de 4 productos')
            ->assertSee('wire:poll.3s="refrescarAprobacion"', escape: false)
            // Mientras trabaja el worker, el botón no está: presionarlo otra
            // vez no haría nada y solo confundiría.
            ->assertDontSee('Aprobar los revisados');
    }

    public function test_el_resumen_sobrevive_a_recargar_la_pagina(): void
    {
        $this->importacion->update([
            'estado'             => 'aprobado',
            'resumen_aprobacion' => [
                'aprobados' => 32, 'productos_nuevos' => 32, 'productos_reusados' => 0,
                'variantes' => 96, 'saltados' => [], 'colores_agregados' => [],
                'tallas_creadas' => [], 'pendientes' => 0, 'faltan' => 0, 'se_corto' => false,
            ],
        ]);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->assertSee('Lo que entró al catálogo')
            ->assertSee('32');
    }

    /**
     * El detector de "trabado" NO puede mirar el updated_at de la importación:
     * el worker no toca esa fila mientras trabaja, así que un catálogo de 166
     * productos (unos seis minutos) se declararía trabado a los dos. Lo que se
     * mueve, producto por producto, son las filas de staging.
     */
    public function test_un_catalogo_que_avanza_no_se_declara_trabado(): void
    {
        $filas = $this->revisados(3);
        $this->importacion->update(['estado' => 'aprobando']);

        // La importación lleva diez minutos sin tocarse...
        DB::table('importaciones_catalogo')
            ->where('id', $this->importacion->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        // ...pero un producto acaba de entrar hace un segundo.
        DB::table('productos_importados_staging')
            ->where('id', $filas[0]->id)
            ->update(['updated_at' => now()]);

        $this->assertFalse(
            $this->importacion->fresh()->aprobacionPareceTrabada(),
            'Está avanzando: las filas se mueven aunque la importación no.'
        );
    }

    public function test_un_catalogo_que_no_avanza_si_se_declara_trabado(): void
    {
        $filas = $this->revisados(3);
        $this->importacion->update(['estado' => 'aprobando']);

        DB::table('importaciones_catalogo')
            ->where('id', $this->importacion->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        DB::table('productos_importados_staging')
            ->whereIn('id', collect($filas)->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        $trabada = $this->importacion->fresh();

        $this->assertTrue($trabada->aprobacionPareceTrabada());
        $this->assertStringContainsString('sin avanzar', $trabada->textoDeAprobacion());
    }
}
