<?php

namespace Tests\Feature\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\ExtraerProductosDelCatalogoAction;
use App\Services\Ia\LectorDeCatalogos;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Apoyo\LectorDeCatalogosFalso;
use Tests\TestCase;

/**
 * Candados para no quemar el saldo de la IA sin darse cuenta.
 *
 * El problema no era técnico: la pantalla no ayudaba a no gastar. El botón que
 * cuesta ~$1 USD no pedía confirmación (el de Quitar, que es gratis, sí), el
 * gasto del mes no se veía en ninguna parte, y cuando el proveedor contestaba
 * que no hay saldo el aviso decía «intenta de nuevo», que es justo lo que no
 * hay que hacer: cada intento vuelve a subir el PDF y a pagar la lectura.
 *
 * Ninguna prueba llama al API: usan el lector falso.
 */
class CandadosDeGastoIaTest extends TestCase
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
            'filesystems.catalogos_disk' => self::DISCO,
            'ia.paginas_por_bloque' => 10,
            'ia.max_usd_por_mes' => 12,
        ]);

        $this->lector = new LectorDeCatalogosFalso([
            3 => ['marca' => 'Impuls', 'modelo' => '355-37', 'color' => 'Miel', 'precio_publico' => 699.0, 'dudosos' => []],
        ]);

        $this->app->instance(LectorDeCatalogos::class, $this->lector);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    /** Un PDF con el número de páginas que se le pida. */
    private function pdfDe(int $paginas): string
    {
        $pdf = "%PDF-1.6\n1 0 obj\n<< /Type /Pages /Kids [] /Count {$paginas} >>\nendobj\n";

        for ($i = 0; $i < $paginas; $i++) {
            $pdf .= ($i + 2)." 0 obj\n<< /Type /Page /Parent 1 0 R >>\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function importacion(array $extra = []): ImportacionCatalogo
    {
        $ruta = 'catalogos-ia/2026/10/prueba.pdf';
        Storage::disk(self::DISCO)->put($ruta, $this->pdfDe(25));

        return ImportacionCatalogo::create([
            'linea_id' => 1,
            'archivo_url' => $ruta,
            'tipo_archivo' => 'pdf',
            'estado' => 'cargado',
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
            ...$extra,
        ]);
    }

    // ------------------------------------------------------------------
    // El aviso de saldo agotado
    // ------------------------------------------------------------------

    public function test_si_se_acabo_el_saldo_el_aviso_lo_dice_y_no_invita_a_reintentar(): void
    {
        $this->lector->sinSaldo = true;
        $importacion = $this->importacion();

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($importacion);

        $importacion->refresh();

        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('saldo', $importacion->mensaje_error);
        $this->assertStringContainsString('cada intento cuesta', $importacion->mensaje_error);

        // Lo que fallaba: el aviso genérico empujaba justo a la acción que
        // cobra otra vez.
        $this->assertStringNotContainsString('Intenta de nuevo', $importacion->mensaje_error);
    }

    /**
     * El SDK solo reintenta 408, 409, 429 y 5xx. Un 400 llega una vez, así
     * que el gasto de un saldo agotado es una lectura, no cinco.
     */
    public function test_el_400_de_saldo_no_se_reintenta_solo(): void
    {
        $this->lector->sinSaldo = true;

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($this->importacion());

        $this->assertCount(1, $this->lector->bloquesPedidos);
    }

    /**
     * Importante que no etiquete de «saldo» cualquier 400: si lo hiciera, un
     * error de configuración mandaría a revisar la cuenta de la IA en balde.
     */
    public function test_otro_error_del_proveedor_sigue_con_el_aviso_de_siempre(): void
    {
        $this->lector->otroErrorDelProveedor = true;
        $importacion = $this->importacion();

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($importacion);

        $importacion->refresh();

        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('No se pudo leer el catálogo con la IA', $importacion->mensaje_error);
        $this->assertStringNotContainsString('saldo', $importacion->mensaje_error);
    }

    /** Pase lo que pase, el catálogo no se queda guardado en el proveedor. */
    public function test_aunque_falle_por_saldo_el_archivo_se_borra_del_proveedor(): void
    {
        $this->lector->sinSaldo = true;
        $importacion = $this->importacion();

        app(ExtraerProductosDelCatalogoAction::class)->ejecutar($importacion);

        $this->assertTrue($this->lector->borrado);
        $this->assertNull($importacion->refresh()->archivo_ia_id);
    }

    // ------------------------------------------------------------------
    // La confirmación antes de gastar
    // ------------------------------------------------------------------

    public function test_el_primer_procesar_avisa_que_cuesta_sin_inventar_un_monto(): void
    {
        // Recién subido no se sabe cuántas páginas tiene: eso se cuenta
        // dentro de la lectura, así que no hay estimado que dar.
        $importacion = $this->importacion();

        $aviso = Livewire::actingAs($this->adminGeneral())
            ->test('admin.catalogos-importaciones')
            ->instance()
            ->avisoAntesDeLeer($importacion->loadCount('productosStaging'));

        $this->assertStringContainsString('cuesta dinero de verdad', $aviso);
        $this->assertStringContainsString('depende de cuántas páginas', $aviso);

        // Sin productos leídos, no habla de borrar nada.
        $this->assertStringNotContainsString('borra', $aviso);
    }

    public function test_reintentar_avisa_que_borra_lo_leido_y_vuelve_a_cobrar(): void
    {
        $importacion = $this->importacion(['estado' => 'error', 'paginas' => 76]);

        ProductoImportadoStaging::create([
            'importacion_id' => $importacion->id,
            'datos_extraidos' => ['pagina' => 3, 'modelo' => '355-37'],
            'estado' => 'pendiente',
        ]);

        $aviso = Livewire::actingAs($this->adminGeneral())
            ->test('admin.catalogos-importaciones')
            ->instance()
            ->avisoAntesDeLeer($importacion->loadCount('productosStaging'));

        $this->assertStringContainsString('Ya se leyeron 1 productos', $aviso);
        $this->assertStringContainsString('ya se pagó', $aviso);
        $this->assertStringContainsString('los borra', $aviso);
        // 76 páginas por el precio por página: cerca de un dólar.
        $this->assertStringContainsString('$0.99', $aviso);
        // Y le ofrece la salida barata.
        $this->assertStringContainsString('Revisar', $aviso);
    }

    /**
     * El aviso vive en el modal del panel, no en un wire:confirm. Hay que
     * abrirlo: el texto no está en la tabla hasta que se pide confirmar.
     */
    public function test_el_boton_que_cuesta_dinero_pide_confirmacion(): void
    {
        $importacion = $this->importacion();

        Livewire::actingAs($this->adminGeneral())
            ->test('admin.catalogos-importaciones')
            ->assertDontSee('wire:confirm')
            ->assertDontSee('cuesta dinero de verdad')
            ->call('pedirProcesar', $importacion->id)
            ->assertSee('Leer este catálogo con la IA cuesta dinero de verdad')
            ->call('cancelarConfirmacion');

        $this->assertSame('cargado', $importacion->fresh()->estado);
    }

    // ------------------------------------------------------------------
    // El gasto del mes a la vista
    // ------------------------------------------------------------------

    public function test_la_pantalla_enseña_lo_gastado_del_mes_contra_el_tope_de_la_app(): void
    {
        $this->importacion(['estado' => 'requiere_revision', 'paginas' => 76, 'costo_usd' => 0.85]);
        $this->importacion(['estado' => 'requiere_revision', 'paginas' => 40, 'costo_usd' => 0.52]);

        Livewire::actingAs($this->adminGeneral())
            ->test('admin.catalogos-importaciones')
            ->assertSee('Gasto de la IA este mes')
            ->assertSee('$1.37')
            ->assertSee('$12.00')
            // Que nadie lo confunda con el saldo de la cuenta de la IA.
            ->assertSee('tope de la app')
            ->assertSee('no el saldo de la cuenta de la IA');
    }

    public function test_si_no_hay_tope_configurado_lo_dice_en_vez_de_enseñar_una_barra(): void
    {
        config(['ia.max_usd_por_mes' => 0]);

        Livewire::actingAs($this->adminGeneral())
            ->test('admin.catalogos-importaciones')
            ->assertSee('sin tope configurado');
    }
}
