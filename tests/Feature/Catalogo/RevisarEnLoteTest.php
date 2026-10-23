<?php

namespace Tests\Feature\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\CompletarCampoEnLoteAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-282 — Revisar un catálogo sin abrir 23 formularios.
 *
 * Salió con el Impuls. Ese catálogo no dice en ninguna página si hay medias
 * tallas, así que la IA lo dejaba en null y lo marcaba dudoso en 23 de 26
 * productos. Y como sus marcas SÍ vienen impresas, el relleno en lote no
 * tenía nada que rellenar y no marcaba nada como revisado.
 *
 * Con el Cklass no se había visto porque ahí ningún producto traía marca:
 * rellenarla marcó los 166 de rebote. Fue suerte, no diseño.
 */
class RevisarEnLoteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        $this->importacion = ImportacionCatalogo::create([
            'linea_id'                => 1,
            'archivo_url'             => 'catalogos-ia/impuls.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 15,
            'iniciada_por_usuario_id' => Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function fila(array $extra = [], array $dudosos = []): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $this->importacion->id,
            'datos_extraidos' => [
                'pagina'          => 11,
                'marca'           => 'PUMA',
                'modelo'          => '39618007',
                'nombre'          => 'Tenis casual',
                'color'           => 'BLANCO/ORO',
                'codigo_catalogo' => '878778',
                'precio_publico'  => 1269.0,
                'talla_desde'     => '22',
                'talla_hasta'     => '27',
                'medios_numeros'  => null,
                'disponibilidad'  => 'HASTA AGOTAR EXISTENCIAS',
                ...$extra,
            ],
            'campos_dudosos' => $dudosos,
            'estado'         => 'pendiente',
        ]);
    }

    private function panel()
    {
        return Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.revisar-catalogo', ['importacion' => $this->importacion->id]);
    }

    // ------------------------------------------------------------------
    // Medias tallas en lote
    // ------------------------------------------------------------------

    public function test_las_medias_tallas_se_pueden_contestar_de_una_vez(): void
    {
        $a = $this->fila([], ['medios_numeros']);
        $b = $this->fila(['codigo_catalogo' => '830940'], ['medios_numeros']);

        $rellenados = app(CompletarCampoEnLoteAction::class)
            ->ejecutar($this->importacion, 'medios_numeros', 'no');

        $this->assertSame(2, $rellenados);

        foreach ([$a, $b] as $fila) {
            $fila->refresh();
            $this->assertFalse($fila->datos_extraidos['medios_numeros'], 'Tiene que quedar booleano, no texto.');
            $this->assertSame([], $fila->campos_dudosos);
            $this->assertSame('corregido', $fila->estado);
        }
    }

    public function test_un_si_queda_como_booleano_verdadero(): void
    {
        $fila = $this->fila([], ['medios_numeros']);

        app(CompletarCampoEnLoteAction::class)->ejecutar($this->importacion, 'medios_numeros', 'si');

        $this->assertTrue($fila->refresh()->datos_extraidos['medios_numeros']);
    }

    /**
     * Lo que estuvo a punto de romperse: con la comprobación vieja,
     * trim((string) false) daba "" y un false se trataba como hueco. Rellenar
     * en lote habría pisado lo que la IA sí leyó.
     */
    public function test_no_pisa_un_solo_enteros_que_la_ia_si_leyo(): void
    {
        $leido = $this->fila(['medios_numeros' => false]);
        $sinLeer = $this->fila(['codigo_catalogo' => '830940', 'medios_numeros' => null], ['medios_numeros']);

        $rellenados = app(CompletarCampoEnLoteAction::class)
            ->ejecutar($this->importacion, 'medios_numeros', 'si');

        $this->assertSame(1, $rellenados, 'Solo debía rellenar el que estaba en null.');
        $this->assertFalse($leido->refresh()->datos_extraidos['medios_numeros']);
        $this->assertTrue($sinLeer->refresh()->datos_extraidos['medios_numeros']);

        // Y el que no tocó sigue esperando revisión.
        $this->assertSame('pendiente', $leido->estado);
    }

    public function test_un_valor_que_no_es_si_ni_no_se_rechaza(): void
    {
        $this->fila([], ['medios_numeros']);

        $this->expectException(\InvalidArgumentException::class);

        app(CompletarCampoEnLoteAction::class)->ejecutar($this->importacion, 'medios_numeros', 'quien sabe');
    }

    // ------------------------------------------------------------------
    // Dar por revisados los que no tienen dudas
    // ------------------------------------------------------------------

    public function test_los_que_no_tienen_dudas_se_dan_por_revisados_de_una_vez(): void
    {
        $limpio = $this->fila(['codigo_catalogo' => '889879']);
        $conDuda = $this->fila(['codigo_catalogo' => '830940'], ['medios_numeros']);

        $this->panel()->call('darPorRevisadosLosLimpios');

        $this->assertSame('corregido', $limpio->refresh()->estado);

        // El que tiene una duda real sigue pidiendo que alguien lo mire.
        $this->assertSame('pendiente', $conDuda->refresh()->estado);
    }

    public function test_el_boton_dice_cuantos_hay_y_desaparece_cuando_no_quedan(): void
    {
        $this->fila(['codigo_catalogo' => '889879']);
        $this->fila(['codigo_catalogo' => '830940'], ['medios_numeros']);

        $panel = $this->panel();
        $panel->assertSee('Dar por revisados los 1 sin dudas');

        $panel->call('darPorRevisadosLosLimpios')
            ->assertDontSee('Dar por revisados los');
    }

    public function test_si_no_hay_ninguno_sin_dudas_lo_dice_y_no_toca_nada(): void
    {
        $conDuda = $this->fila([], ['medios_numeros']);

        $this->panel()
            ->call('darPorRevisadosLosLimpios')
            ->assertSet('esError', false)
            ->assertSee('hay que mirarlos uno por uno');

        $this->assertSame('pendiente', $conDuda->refresh()->estado);
    }

    /** No aprueba: solo deja listos para que una persona apruebe. */
    public function test_no_aprueba_nada_por_su_cuenta(): void
    {
        $limpio = $this->fila();

        $this->panel()->call('darPorRevisadosLosLimpios');

        $this->assertNull($limpio->refresh()->producto_campana_creado_id);
        $this->assertSame('corregido', $limpio->estado);
    }
}
